<?php
declare(strict_types=1);

/* Build-time helper. It never runs during installation or PDF rendering. */
if (PHP_SAPI !== 'cli' || !isset($argv[1]) || !is_dir($argv[1])) {
    fwrite(STDERR, "Uso: php build-fonts.php /diretorio-vazio-para-saida\n");
    exit(1);
}
if (PHP_VERSION_ID < 80200) {
    fwrite(STDERR, "A conversão requer PHP 8.2 ou superior.\n");
    exit(1);
}
require_once __DIR__ . '/bootstrap.php';
foreach (['LiberationSans-Regular.ttf', 'LiberationSans-Bold.ttf'] as $font) {
    $converted = new \NfseNacional\PdfVendor\Com\Tecnick\Pdf\Font\Import(
        __DIR__ . '/fonts/source/' . $font,
        rtrim($argv[1], '/\\') . '/',
        'TrueTypeUnicode'
    );
    fwrite(STDOUT, $converted->getFontName() . "\n");
}
