<?php

namespace Drupal\bongolava_job\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Extension\ThemeHandlerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Request;

/**
 * Serves the Bongolava Vue SPA shell for all client-side routes.
 *
 * Drupal handles /jobs, /profils, /evenements/42, etc. by returning the same
 * HTML shell. Vue Router then picks up the path from window.location and
 * renders the correct view without a round-trip to the server.
 */
final class SpaController extends ControllerBase {

  public function __construct(
    private readonly ThemeHandlerInterface $themeHandler,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('theme_handler'),
    );
  }

  public function shell(Request $request): Response {
    // Only intercept when bongolava is the active theme.
    $activeTheme = $this->config('system.theme')->get('default');
    if ($activeTheme !== 'bongolava') {
      throw new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException();
    }

    $themePath = $this->themeHandler->getTheme('bongolava')->getPath();
    $root      = \Drupal::root();
    $distAbs   = $root . '/' . $themePath . '/dist/assets';

    $mtJs  = is_readable($distAbs . '/index.js')  ? filemtime($distAbs . '/index.js')  : 0;
    $mtCss = is_readable($distAbs . '/style.css') ? filemtime($distAbs . '/style.css') : 0;
    $v     = (string) max($mtJs, $mtCss);

    $basePath = base_path();
    $distUrl  = rtrim($basePath, '/') . '/' . $themePath . '/dist';

    $account  = $this->currentUser();
    $settings = json_encode([
      'path' => [
        'baseUrl'    => $basePath,
        'currentPath' => ltrim($request->getPathInfo(), '/'),
        'isFront'    => FALSE,
      ],
      'bongolava' => [
        'basePath'       => $basePath,
        'currentUser'    => [
          'uid'   => (int) $account->id(),
          'name'  => $account->getAccountName(),
          'roles' => array_values($account->getRoles()),
        ],
        'bongolavaJobs'     => [],
        'bongolavaEvents'   => [],
        'bongolavaProfiles' => [],
      ],
    ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG);

    $distUrlJs = htmlspecialchars($distUrl, ENT_QUOTES);
    $v         = htmlspecialchars($v, ENT_QUOTES);

    $html = <<<HTML
<!DOCTYPE html>
<html lang="fr" dir="ltr">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Bongolava Jobs</title>
  <meta name="Generator" content="Drupal 9 (https://www.drupal.org)" />
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Plus+Jakarta+Sans:wght@500;600;700;800&display=swap" rel="stylesheet">
  <script>
    window.__drupalBasePath = "{$this->esc($basePath)}";
    window.__BONGOLAVA_ROUTER_BASE = "";
    window.__bongolavaDistUrl = "{$this->esc($distUrl)}";
  </script>
  <link rel="stylesheet" href="{$distUrlJs}/assets/style.css?v={$v}">
  <style>
    @media (min-width:640px){.sm\:block{display:block}.sm\:flex{display:flex}.sm\:inline-flex{display:inline-flex}.sm\:hidden{display:none}}
    @media (max-width:639.9px){.max-sm\:hidden{display:none}}
    @media (min-width:768px){.md\:block{display:block}.md\:flex{display:flex}.md\:inline-flex{display:inline-flex}.md\:hidden{display:none}.md\:inline-block{display:inline-block}}
    @media (max-width:767.9px){.max-md\:hidden{display:none}}
    @media (min-width:1024px){.lg\:block{display:block}.lg\:flex{display:flex}.lg\:inline-flex{display:inline-flex}.lg\:grid{display:grid}.lg\:hidden{display:none}.lg\:inline-block{display:inline-block}}
    @media (max-width:1023.9px){.max-lg\:hidden{display:none}}
    @media (min-width:1280px){.xl\:block{display:block}.xl\:flex{display:flex}.xl\:hidden{display:none}}
  </style>
</head>
<body>
  <div id="bongolava-app"></div>
  <script type="application/json" data-drupal-selector="drupal-settings-json">{$settings}</script>
  <script type="module" src="{$distUrlJs}/assets/index.js?v={$v}"></script>
</body>
</html>
HTML;

    return new Response($html, 200, [
      'Content-Type'  => 'text/html; charset=UTF-8',
      'Cache-Control' => 'no-store, private',
      'X-Frame-Options' => 'SAMEORIGIN',
    ]);
  }

  private function esc(string $v): string {
    return addslashes($v);
  }

}
