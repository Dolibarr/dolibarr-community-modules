<?php

namespace Stancer\Tests\Http;

class AdminPagesHttpTest extends HttpTestCase
{
    protected static function getRouterPath(): string
    {
        return dirname(__DIR__, 3) . '/test/http/admin-router.php';
    }

    public function testAdminRouterPing(): void
    {
        $response = $this->get('/ping');
        $this->assertStatusCode(200, $response);
        $this->assertJsonEquals('status', 'ok', $response);
    }

    /**
     * @dataProvider adminPagesProvider
     */
    public function testAdminPageLoadsWithoutError(string $page): void
    {
        $response = $this->get('/admin/' . $page);
        $this->assertNoPhpError($response, 'admin/' . $page);
    }

    public static function adminPagesProvider(): array
    {
        $adminDir = dirname(__DIR__, 3) . '/admin';
        $files = glob($adminDir . '/*.php');
        $cases = [];
        foreach ($files as $file) {
            $basename = basename($file);
            $cases[$basename] = [$basename];
        }
        ksort($cases);
        return $cases;
    }

    /**
     * @dataProvider rootPagesProvider
     */
    public function testRootPageLoadsWithoutError(string $page): void
    {
        $url = '/' . $page;
        // Pages that need GET parameters to render without redirect/exit
        if ($page === 'stancer_thirdparty.php') {
            $url .= '?socid=1';
        }
        $response = $this->get($url);
        $this->assertNoPhpError($response, $page);
    }

    public static function rootPagesProvider(): array
    {
        $projectRoot = dirname(__DIR__, 3);
        // Excluded pages:
        //  - main.inc.php / config.php : not real pages (Dolibarr internals / module conf)
        //  - vrac_ne_pas_embarquer.php : dev-only scratch file, not shipped
        $excluded = ['config.php', 'main.inc.php', 'vrac_ne_pas_embarquer.php'];
        $files = glob($projectRoot . '/*.php');
        $cases = [];
        foreach ($files as $file) {
            $basename = basename($file);
            if (in_array($basename, $excluded, true)) {
                continue;
            }
            $cases[$basename] = [$basename];
        }
        ksort($cases);
        return $cases;
    }

    /**
     * The About page must stay within the Dolibarr community module rules:
     * no shop / donation link, and the publisher mention followed by the
     * community credit.
     *
     * @see https://wiki.dolibarr.org/index.php/Modules_-_Rules_for_community_modules
     */
    public function testAboutPageHasNoCommercialShopLink(): void
    {
        $response = $this->get('/admin/about.php');
        $this->assertNoPhpError($response, 'admin/about.php');

        $this->assertStringNotContainsString(
            'shop.cap-rel.fr',
            $response['body'],
            'About page must not link to the commercial shop'
        );
        $this->assertStringNotContainsString(
            'button-donate',
            $response['body'],
            'About page must not show a donation button'
        );
    }

    /**
     * @dataProvider ajaxPagesProvider
     */
    public function testAjaxPageLoadsWithoutError(string $page): void
    {
        $response = $this->get('/ajax/' . $page);
        $this->assertNoPhpError($response, 'ajax/' . $page);
    }

    public static function ajaxPagesProvider(): array
    {
        $ajaxDir = dirname(__DIR__, 3) . '/ajax';
        if (!is_dir($ajaxDir)) {
            return [];
        }
        $files = glob($ajaxDir . '/*.php');
        $cases = [];
        foreach ($files as $file) {
            $basename = basename($file);
            $cases[$basename] = [$basename];
        }
        ksort($cases);
        return $cases;
    }

    /**
     * @dataProvider publicPagesProvider
     */
    public function testPublicPageLoadsWithoutError(string $page): void
    {
        $response = $this->get('/public/' . $page);
        $this->assertNoPhpError($response, 'public/' . $page);
    }

    public static function publicPagesProvider(): array
    {
        $publicDir = dirname(__DIR__, 3) . '/public';
        if (!is_dir($publicDir)) {
            return [];
        }
        $files = glob($publicDir . '/*.php');
        $cases = [];
        foreach ($files as $file) {
            $basename = basename($file);
            $cases[$basename] = [$basename];
        }
        ksort($cases);
        return $cases;
    }

    /**
     * @dataProvider allPostActionsProvider
     */
    public function testPostActionWithoutError(string $page, string $action, string $urlPrefix): void
    {
        $response = $this->post($urlPrefix . $page, [
            'action' => $action,
            'token' => 'test',
            'confirm' => 'yes',
        ]);

        $this->assertNoPhpError($response, "$urlPrefix$page (POST action=$action)");
    }

    /**
     * Scan admin/, root, ajax/, and public/ PHP files for action values.
     */
    public static function allPostActionsProvider(): array
    {
        $projectRoot = dirname(__DIR__, 3);
        $cases = [];
        $cases += self::extractPostActions($projectRoot . '/admin', '/admin/');
        $cases += self::extractPostActions($projectRoot, '/');
        $cases += self::extractPostActions($projectRoot . '/ajax', '/ajax/');
        $cases += self::extractPostActions($projectRoot . '/public', '/public/');
        ksort($cases);
        return $cases;
    }

    /**
     * Extract action values from PHP files in a directory.
     *
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    private static function extractPostActions(string $dir, string $urlPrefix): array
    {
        if (!is_dir($dir)) {
            return [];
        }
        $files = glob($dir . '/*.php');
        $cases = [];
        $skipActions = ['create', 'edit', 'delete', 'view', 'specimen'];
        $excludedFiles = ['main.inc.php', 'config.php', 'vrac_ne_pas_embarquer.php'];
        foreach ($files as $file) {
            $basename = basename($file);
            if (in_array($basename, $excludedFiles, true)) {
                continue;
            }
            $content = file_get_contents($file);
            if (preg_match_all('/\$action\s*===?\s*[\'"]([a-z_]+)[\'"]/i', $content, $matches)) {
                $actions = array_unique($matches[1]);
                foreach ($actions as $act) {
                    if (in_array($act, $skipActions, true)) {
                        continue;
                    }
                    $label = "$urlPrefix$basename action=$act";
                    $cases[$label] = [$basename, $act, $urlPrefix];
                }
            }
        }
        return $cases;
    }
}
