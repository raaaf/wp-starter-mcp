<?php

declare(strict_types=1);

namespace WpStarterMcp\Tests\Unit;

use WpStarterMcp\BearerGate;

final class BearerGateTest extends WpTestCase
{
    private function token(): string
    {
        $this->allowUser(3);

        return (new \WpStarterMcp\OAuth\TokenStore())->issue('access', 3600, ['user_id' => 3, 'family_id' => 'f1', 'client_id' => 'c', 'scope' => 'mcp:read']);
    }

    private function request(string $uri, ?string $token): void
    {
        $_SERVER['REQUEST_URI'] = $uri;
        if ($token !== null) {
            $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
        }
    }

    public function testEarlyCallerBeforeAfterSetupThemeDoesNotTouchRestUrl(): void
    {
        $this->request('/wp-json/wpsmcp/v1/mcp', $this->token());
        \WpState::$afterSetupTheme = 0;

        $this->assertFalse(BearerGate::determineUser(false));
        $this->assertFalse(\WpState::$restUrlCalled);
    }

    public function testCapabilityFilterResolvingTheUserDoesNotRecurse(): void
    {
        $this->request('/wp-json/wpsmcp/v1/mcp', $this->token());
        $depth = 0;
        \WpState::$onUserCan = function () use (&$depth): void {
            if (++$depth > 5) {
                throw new \LogicException('recursion');
            }
            BearerGate::determineUser(false);
        };

        $this->assertSame(3, BearerGate::determineUser(false));
    }

    public function testTokenAuthenticatesOnTheMcpPath(): void
    {
        $this->request('/wp-json/wpsmcp/v1/mcp', $this->token());

        $this->assertSame(3, BearerGate::determineUser(false));
    }

    public function testTrailingSlashStillMatches(): void
    {
        $this->request('/wp-json/wpsmcp/v1/mcp/?x=1', $this->token());

        $this->assertSame(3, BearerGate::determineUser(false));
    }

    public function testSubdirectoryPathMatches(): void
    {
        \WpState::$home = 'https://example.test/blog';
        $this->request('/blog/wp-json/wpsmcp/v1/mcp', $this->token());

        $this->assertSame(3, BearerGate::determineUser(false));
    }

    public function testTokenDoesNotAuthenticateOnWpV2Path(): void
    {
        $this->request('/wp-json/wp/v2/users/me', $this->token());

        $this->assertFalse(BearerGate::determineUser(false));
    }

    public function testRestRouteQueryOnOtherPathDoesNotAuthenticate(): void
    {
        $this->request('/?rest_route=/wp/v2/users/me', $this->token());

        $this->assertFalse(BearerGate::determineUser(false));
    }

    public function testExistingUserIsNotOverridden(): void
    {
        $this->request('/wp-json/wpsmcp/v1/mcp', $this->token());

        $this->assertSame(9, BearerGate::determineUser(9));
    }

    public function testBearerOnMcpPathWithOverriddenRouteIsRejectedAtDispatch(): void
    {
        $this->request('/wp-json/wpsmcp/v1/mcp?rest_route=/wp/v2/users/me', $this->token());

        $result = BearerGate::preDispatch(null, null, new \WP_REST_Request('/wp/v2/users/me'));

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame(['status' => 401], $result->data);
    }

    public function testBearerOfAnotherPluginOnAnotherPathPassesDispatch(): void
    {
        $this->request('/wp-json/jwt/v1/orders', 'foreign-jwt');

        $this->assertNull(BearerGate::preDispatch(null, null, new \WP_REST_Request('/jwt/v1/orders')));
    }

    public function testBearerOnMcpRouteIsNotRejectedAtDispatch(): void
    {
        $this->request('/wp-json/wpsmcp/v1/mcp', $this->token());

        $this->assertNull(BearerGate::preDispatch(null, null, new \WP_REST_Request('/wpsmcp/v1/mcp/')));
    }

    public function testRequestWithoutBearerPassesDispatch(): void
    {
        $this->assertNull(BearerGate::preDispatch(null, null, new \WP_REST_Request('/wp/v2/posts')));
    }

    public function testInvalidTokenOnMcpPathIsAnError(): void
    {
        $this->request('/wp-json/wpsmcp/v1/mcp', 'bogus');

        $this->assertInstanceOf(\WP_Error::class, BearerGate::authenticationErrors(null));
    }

    public function testUnauthorizedMcpAnswerCarriesResourceMetadata(): void
    {
        $response = BearerGate::postDispatch(new \WP_HTTP_Response(403), null, new \WP_REST_Request('/wpsmcp/v1/mcp'));

        $this->assertSame(401, $response->get_status());
        $this->assertStringContainsString(
            'resource_metadata="https://example.test/.well-known/oauth-protected-resource/wp-json/wpsmcp/v1/mcp"',
            $response->headers['WWW-Authenticate']
        );
    }

    public function testOtherRoutesKeepTheirStatus(): void
    {
        $response = BearerGate::postDispatch(new \WP_HTTP_Response(403), null, new \WP_REST_Request('/wp/v2/posts'));

        $this->assertSame(403, $response->get_status());
        $this->assertArrayNotHasKey('WWW-Authenticate', $response->headers);
    }
}
