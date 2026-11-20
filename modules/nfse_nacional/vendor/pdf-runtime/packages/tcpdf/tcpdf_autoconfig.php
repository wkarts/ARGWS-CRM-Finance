<?php
declare(strict_types=1);

/* Module replacement for TCPDF autoconfiguration. It does not search the host
 * application, load Composer, change $_SERVER, or define unprefixed constants.
 * Defaults needed by the isolated facade are set once for this module only. */
(static function (): void {
    $runtime = dirname(__DIR__, 2) . '/';
    $defaults = [
        'K_TCPDF_EXTERNAL_CONFIG' => true,
        'K_PATH_MAIN' => $runtime,
        'K_PATH_FONTS' => $runtime . 'fonts/nt008/',
        'K_PATH_URL' => '',
        'K_PATH_IMAGES' => dirname($runtime, 2) . '/assets/',
        'K_PATH_CACHE' => rtrim(sys_get_temp_dir(), '/\\') . '/',
        'K_BLANK_IMAGE' => '_blank.png',
        'PDF_PAGE_FORMAT' => 'A4',
        'PDF_PAGE_ORIENTATION' => 'P',
        'PDF_UNIT' => 'mm',
        'PDF_CREATOR' => 'NFS-e Nacional',
        'PDF_AUTHOR' => '',
        'PDF_HEADER_TITLE' => '',
        'PDF_HEADER_STRING' => '',
        'PDF_HEADER_LOGO' => '',
        'PDF_HEADER_LOGO_WIDTH' => 0,
        'PDF_MARGIN_HEADER' => 5,
        'PDF_MARGIN_FOOTER' => 10,
        'PDF_MARGIN_TOP' => 27,
        'PDF_MARGIN_BOTTOM' => 25,
        'PDF_MARGIN_LEFT' => 15,
        'PDF_MARGIN_RIGHT' => 15,
        'PDF_FONT_NAME_MAIN' => 'arial',
        'PDF_FONT_NAME_DATA' => 'microsoftsansserif',
        'PDF_FONT_MONOSPACED' => 'arial',
        'PDF_FONT_SIZE_MAIN' => 10,
        'PDF_FONT_SIZE_DATA' => 8,
        'PDF_IMAGE_SCALE_RATIO' => 96 / 72,
        'HEAD_MAGNIFICATION' => 1.1,
        'K_CELL_HEIGHT_RATIO' => 1.25,
        'K_TITLE_MAGNIFICATION' => 1.3,
        'K_SMALL_RATIO' => 2 / 3,
        'K_THAI_TOPCHARS' => true,
        'K_TCPDF_CALLS_IN_HTML' => false,
        'K_ALLOWED_TCPDF_TAGS' => '',
        'K_TCPDF_THROW_EXCEPTION_ERROR' => true,
        'K_TIMEZONE' => date_default_timezone_get(),
        'K_ALLOWED_PATHS' => [],
        'K_ALLOWED_HOSTS' => [],
        'K_MAX_REMOTE_SIZE' => 1024,
        'K_CURLOPTS' => [],
    ];
    foreach ($defaults as $name => $value) {
        define('NFSE_NACIONAL_PDF_' . $name, $value);
    }
})();
