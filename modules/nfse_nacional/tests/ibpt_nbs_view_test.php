<?php
declare(strict_types=1);
namespace NfseNacional {
    final class DraftTax {
        public static function state(array $provider): string { return (string) ($provider['state'] ?? ''); }
    }
    final class IbptTable {
        public static array $rows = [];
        public static int $fetches = 0;
        public static function current(): self { return new self(); }
        public function services(string $uf): array {
            ++self::$fetches;
            return self::$rows[$uf] ?? [];
        }
    }
}
namespace {
    if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
    define('BASEPATH', __DIR__);
    $checks = 0;
    function viewCheck(bool $success, string $label): void {
        if (!$success) { throw new \RuntimeException('FALHA: '.$label); }
        ++$GLOBALS['checks'];
    }
    class FakeNbsModel {
        public string $uf = 'BA';
        public int $settingsCalls = 0;
        public function settings(): array { ++$this->settingsCalls; return ['provider' => ['state' => $this->uf]]; }
    }
    $model = new FakeNbsModel();
    function nfse_nacional_model(): FakeNbsModel { return $GLOBALS['model']; }
    function renderNbsField(string $code, string $prefix): string {
        require dirname(__DIR__) . '/views/_ui_helpers.php';
        ob_start();
        $nfseNbsSelect($prefix . '[nbs]', 'NBS', $code);
        return ob_get_clean();
    }
    \NfseNacional\IbptTable::$rows = ['BA' => [
        ['code' => '101011000', 'description' => 'Construcao civil'],
        ['code' => '115011000', 'description' => '<script>alert("x")</script> Tecnologias'],
    ]];
    $html = renderNbsField('115011000', 'draft[service]');
    viewCheck(str_contains($html, 'data-nfse-nbs-catalog') && str_contains($html, 'data-live-search="true"'), 'Tabela instalada produz seletor pesquisavel');
    viewCheck(str_contains($html, 'name="draft[service][nbs]"') && str_contains($html, 'value="115011000"'), 'Campo e valor do rascunho continuam iguais');
    viewCheck(str_contains($html, 'data-tokens="115011000'), 'Codigo e descricao formam palavras-chave');
    viewCheck(!str_contains($html, '<script>') && str_contains($html, '&lt;script&gt;'), 'Descricoes sao escapadas contra XSS');
    viewCheck(str_contains($html, '2 serviços NBS'), 'Contagem da tabela instalada disponivel');
    $legacy = renderNbsField('999999999', 'profile[emission_profile][service]');
    viewCheck(str_contains($legacy, '999999999') && str_contains($legacy, 'data-nfse-legacy-service'), 'NBS legado nao e perdido');
    $model->uf = 'SP';
    $manual = renderNbsField('115011000', 'recipient[emission_profile][service]');
    viewCheck(!str_contains($manual, 'data-nfse-nbs-catalog') && str_contains($manual, 'type="text"'), 'Sem tabela da UF atual: digitacao manual');
    viewCheck(str_contains($manual, 'pattern="[0-9]{9}"') && str_contains($manual, '115011000'), 'Campo livre preserva codigo e exige nove digitos');
    viewCheck(\NfseNacional\IbptTable::$fetches === 3, 'Uma consulta local por formulario sem requisicoes remotas');
    echo "OK: {$checks} verificacoes do seletor NBS.\n";
}
