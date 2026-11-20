<?php
declare(strict_types=1);

namespace NfseNacional;

/** DANFSe v2.0: official content comes only from the returned XML.
 * Local previews reuse the layout through a separate, non-fiscal projection.
 * Layout: NT 008 v1.02, 14/07/2026, section 2.4 and Annex I.
 * The PDF engine and fonts are bundled with this module and isolated from Perfex.
 * No renderer, font or web resource is downloaded at runtime.
 */
final class Danfe
{
    private $pdf;
    /** @var NfseDocument|DraftPreviewDocument */
    private $note;
    private bool $preview;
    private array $fiscalStatus;
    private array $document;
    private string $labelFont;
    private string $contentFont;
    private static ?array $municipalities = null;

    private function __construct($note, array $fiscalStatus = [], array $document = [])
    {
        $this->pdf = PdfRuntime::create();
        $this->note = $note;
        $this->preview = $note instanceof DraftPreviewDocument;
        $this->fiscalStatus = $fiscalStatus;
        $this->document = $document;
        $fonts = self::fontDiagnostics();
        $this->labelFont = $fonts['label_font'];
        $this->contentFont = $fonts['content_font'];
        $this->pdf->setPrintHeader(false);
        $this->pdf->setPrintFooter(false);
        $this->pdf->SetMargins(2, 2, 2);
        $this->pdf->SetAutoPageBreak(false, 0);
        $this->pdf->SetCreator('NFS-e Nacional');
        $this->pdf->SetTitle($this->preview ? 'Prévia do DANFSe - sem validade fiscal' : 'DANFSe ' . $note->number());
        $this->pdf->setCellPaddings(0, 0, 0, 0);
        $this->pdf->setCellHeightRatio(1.05);
        $this->pdf->SetDrawColor(0, 0, 0);
        $this->pdf->SetTextColor(0, 0, 0);
        $this->pdf->SetFillColor(242, 242, 242);
        $this->pdf->AddPage();
    }

    public static function render(string $authorizedXml, array $fiscalStatus = []): string
    {
        $status = (string) ($fiscalStatus['status'] ?? 'authorized');
        if (!in_array($status, ['authorized', 'cancelled'], true)) {
            throw new \InvalidArgumentException('O DANFSe autorizado exige a situação fiscal confirmada.');
        }
        if ($status === 'cancelled' && empty($fiscalStatus['is_cancelled'])) {
            throw new \InvalidArgumentException('O carimbo de cancelamento exige um evento fiscal confirmado.');
        }
        $fiscalStatus['status'] = $status;
        $renderer = new self(new NfseDocument($authorizedXml), $fiscalStatus);
        return $renderer->output();
    }

    /** Pure rendering: never signs, reserves a DPS, persists state or calls an API. */
    public static function preview(array $draft, array $document, array $fiscalStatus = []): string
    {
        $status = (string) ($document['status'] ?? 'draft');
        if (!in_array($status, ['draft', 'ready', 'rejected', 'processing', 'uncertain'], true)) {
            throw new \InvalidArgumentException('A NFS-e autorizada ou cancelada deve ser visualizada a partir do XML fiscal recebido.');
        }
        // The persisted local state always wins over any caller-supplied label.
        $fiscalStatus['status'] = $status;
        $renderer = new self(new DraftPreviewDocument($draft, $document), $fiscalStatus, $document);
        return $renderer->output();
    }

    private function output(): string
    {
        $renderer = $this;
        $renderer->draw();
        if ($renderer->pdf->getNumPages() !== 1) {
            throw new \RuntimeException('O DANFSe não pôde ser mantido em uma única página.');
        }
        $name = $this->preview ? 'Previa-DANFSe' : 'DANFSe-' . $renderer->note->number();
        $bytes = $renderer->pdf->Output($name . '.pdf', 'S');
        if (!is_string($bytes) || strncmp($bytes, '%PDF-', 5) !== 0) {
            throw new \RuntimeException('Não foi possível gerar o DANFSe.');
        }
        return $bytes;
    }

    /** Read-only diagnostics for the administrative settings screen. */
    public static function diagnostics(): array
    {
        try {
            $runtime = PdfRuntime::diagnostics();
            $available = !empty($runtime['available']) && !empty($runtime['fonts_available']);
            return [
                'available' => $available,
                // Preserve this diagnostic key for integrations with earlier module versions.
                'tcpdf_available' => !empty($runtime['engine_available']),
                'fonts_available' => !empty($runtime['fonts_available']),
                'engine' => $runtime['engine'] ?? '',
                'version' => $runtime['version'] ?? '',
                'label_font' => $runtime['label_font'] ?? '',
                'content_font' => $runtime['content_font'] ?? '',
                'font_family' => 'Arial / Microsoft Sans Serif',
                'exact_fonts' => !empty($runtime['fonts_available']),
                'typography_note' => 'Arial nos rótulos e Microsoft Sans Serif no conteúdo, conforme a seção 2.4 da NT 008.',
                'message' => $available
                    ? 'Disponível. O DANFSe utiliza Arial e Microsoft Sans Serif, incorporadas pelo próprio módulo.'
                    : ($runtime['message'] ?? 'Os recursos do DANFSe estão incompletos. Reinstale o pacote completo do módulo.'),
            ];
        } catch (\Throwable $error) {
            return ['available' => false, 'tcpdf_available' => false, 'fonts_available' => false, 'exact_fonts' => false,
                'label_font' => '', 'content_font' => '',
                'message' => 'Não foi possível carregar os recursos do DANFSe. Reinstale o pacote completo do módulo e confira os requisitos de PHP.'];
        }
    }

    /** Only the module's verified original fonts are used; no substitute or host font is selected. */
    public static function fontDiagnostics(): array
    {
        $diagnostics = self::diagnostics();
        return array_intersect_key($diagnostics, array_flip([
            'fonts_available', 'label_font', 'content_font', 'font_family',
            'exact_fonts', 'typography_note', 'message',
        ]));
    }

    private function draw(): void
    {
        $n = $this->note;
        $this->pdf->SetLineWidth(0.3528);
        $this->pdf->Rect(2, 2, 206, 293);
        $this->pdf->SetLineWidth(0.1764);
        $this->pdf->Rect(3, 3, 204, 11.6, 'F');
        $logo = dirname(__DIR__, 2) . '/assets/logo-nfse.png';
        if (is_file($logo)) { $this->pdf->Image($logo, 4.9, 4.4, 40, 0, 'PNG'); }
        $this->plain(54.1, 4.0, 101.9, 3.2, 'DANFSe v2.0', 9, true, 'C');
        $this->plain(54.1, 7.2, 101.9, 3.2, 'Documento Auxiliar da NFS-e', 9, true, 'C');
        if ($this->preview || $this->fiscalStatus['status'] === 'cancelled' || $n->environment() === '2') {
            $this->pdf->SetTextColor(190, 25, 25);
            $header = $this->preview ? 'PRÉVIA SEM VALIDADE FISCAL'
                : ($this->fiscalStatus['status'] === 'cancelled'
                    ? ($n->environment() === '2' ? 'CANCELADA - SEM VALIDADE JURÍDICA' : 'CANCELADA')
                    : 'NFS-e SEM VALIDADE JURÍDICA');
            $this->plain(54.1, 10.5, 101.9, 3.2, $header, 9, true, 'C');
            $this->pdf->SetTextColor(0, 0, 0);
        } else {
            $this->plain(54.1, 10.5, 101.9, 3.2, 'TRANSMITIDA E AUTORIZADA', 8, true, 'C');
        }
        $city = $this->preview ? $this->municipality($n->dpsText('cLocEmi')) : $n->text('xLocEmi');
        $uf = $n->text('emit/enderNac/UF');
        $this->plain(156.2, 3.4, 50.8, 5.8, 'Município: ' . self::join([$city, $uf]), 8);
        $this->plain(156.2, 9.7, 50.8, 2.4, $this->preview ? 'Prévia local para conferência' : 'Ambiente gerador: ' . self::label($n->text('ambGer'), ['1' => 'Prefeitura', '2' => 'Sistema Nacional']), 6);
        $this->plain(156.2, 12.1, 50.8, 2.4, self::label($n->environment(), ['1' => 'Produção', '2' => 'Produção restrita / Homologação']) ?: 'Ambiente não definido', 6);
        $this->line(14.6);
        $this->field(3, 14.8, 153, 7.5, 'CHAVE DE ACESSO DA NFS-E', $this->preview ? 'Não atribuída - documento sem autorização' : $n->accessKey(), false, true);
        $this->field(3, 22.7, 51, 6.7, 'NÚMERO DA NFS-E', $n->number(), false, true);
        $this->field(54.1, 22.7, 50.9, 6.7, 'COMPETÊNCIA DA NFS-E', self::date($n->dpsText('dCompet')), false, true);
        $this->field(105.1, 22.7, 50.9, 6.7, 'DATA E HORA DA EMISSÃO DA NFS-E', self::date($n->text('dhProc')), false, true);
        $this->field(3, 29.6, 51, 6.7, 'NÚMERO DA DPS', $n->dpsText('nDPS'), false, true);
        $this->field(54.1, 29.6, 50.9, 6.7, 'SÉRIE DA DPS', $n->dpsText('serie'), false, true);
        $this->field(105.1, 29.6, 50.9, 6.7, 'DATA E HORA DA EMISSÃO DA DPS', self::date($n->dpsText('dhEmi')), false, true);
        $this->field(3, 36.5, 51, 6.7, 'EMITENTE DA NFS-E', self::label($n->dpsText('tpEmit'), ['1' => 'Prestador', '2' => 'Tomador', '3' => 'Intermediário']), true, true);
        $this->field(54.1, 36.5, 50.9, 6.7, 'SITUAÇÃO DA NFS-E', $this->preview ? $this->stamp() : ($this->fiscalStatus['status'] === 'cancelled' ? 'Cancelada por evento fiscal' : self::label($n->text('cStat'), ['100' => 'NFS-e Gerada', '102' => 'NFS-e de Decisão Judicial', '103' => 'NFS-e Avulsa', '107' => 'NFS-e MEI'])), false, true);
        $this->field(105.1, 36.5, 50.9, 6.7, 'FINALIDADE', self::label($n->dpsText('IBSCBS/finNFSe'), ['0' => 'NFS-e regular']), false, true);
        if ($this->preview) {
            $this->pdf->SetDrawColor(190, 25, 25);
            $this->pdf->SetTextColor(190, 25, 25);
            $this->pdf->Rect(158.2, 17.2, 46.8, 13.8);
            $this->plain(159.2, 19.2, 44.8, 10.0, $this->stamp(), 10, true, 'C');
            $this->pdf->SetDrawColor(0, 0, 0);
            $this->pdf->SetTextColor(0, 0, 0);
            $this->plain(158, 33.1, 47.2, 9.3, 'Conferência dos dados locais. Esta prévia não substitui a NFS-e autorizada e não possui QR de autenticidade.', 6);
        } else {
            $this->pdf->write2DBarcode($n->consultationUrl(), 'QRCODE,M', 174.8, 16.7, 15.2, 15.2,
                ['border' => false, 'padding' => 0, 'fgcolor' => [0, 0, 0], 'bgcolor' => [255, 255, 255]], 'N');
            $this->plain(158, 33.6, 47.2, 8.8, 'A autenticidade desta NFS-e pode ser verificada pela leitura deste código QR ou pela consulta da chave de acesso no portal nacional da NFS-e', 6);
        }

        $this->person('prest', 'PRESTADOR / FORNECEDOR', 43.4, true);
        $this->person('toma', 'TOMADOR / ADQUIRENTE', 69.2);
        $destinationPresent = $n->dpsExists('IBSCBS/dest');
        $intermediaryPresent = $n->dpsExists('interm');
        $destHeight = $destinationPresent ? 19.4 : 3.4;
        $interHeight = $intermediaryPresent ? 19.4 : 3.4;
        if ($destinationPresent) { $this->person('IBSCBS/dest', 'DESTINATÁRIO DA OPERAÇÃO', 88.6); }
        else {
            $this->emptyBlock(88.6, $n->dpsText('IBSCBS/indDest') === '0'
                ? 'O DESTINATÁRIO É O PRÓPRIO TOMADOR/ADQUIRENTE DA OPERAÇÃO'
                : 'DESTINATÁRIO DA OPERAÇÃO NÃO IDENTIFICADO NA NFS-e');
        }
        if ($intermediaryPresent) { $this->person('interm', 'INTERMEDIÁRIO DA OPERAÇÃO', 88.6 + $destHeight); }
        else { $this->emptyBlock(88.6 + $destHeight, 'INTERMEDIÁRIO DA OPERAÇÃO NÃO IDENTIFICADO NA NFS-e'); }
        $serviceY = 88.6 + $destHeight + $interHeight;
        $this->line($serviceY);
        $this->blockTitle($serviceY, 'SERVIÇO PRESTADO');
        $this->field(54.1, $serviceY, 50.9, 6.4, 'Código de Tributação Nacional / Municipal', self::join([$n->dpsText('serv/cServ/cTribNac'), $n->dpsText('serv/cServ/cTribMun')]));
        $this->field(105.1, $serviceY, 50.9, 6.4, 'Código da NBS', $n->dpsText('serv/cServ/cNBS'));
        $this->field(156.2, $serviceY, 50.8, 6.4, 'Local da Prestação / Sigla UF / País', self::join([$this->municipality($n->dpsText('serv/locPrest/cLocPrestacao'), $n->text('xLocPrestacao')), $n->dpsText('serv/locPrest/cPaisPrestacao')]));
        $this->plain(3.8, $serviceY + 6.6, 202.4, 3.7, $n->text('xTribMun') ?: $n->text('xTribNac'), 7);
        $this->field(3, $serviceY + 10.4, 204, 144.3 - ($serviceY + 10.4), 'Descrição do Serviço', $n->dpsText('serv/cServ/xDescServ'));
        $this->municipalTaxes(144.3);
        $year = substr($n->dpsText('dCompet'), 0, 4);
        $showPisRow = $year !== '' && $year <= '2026';
        $this->federalTaxes(170.2, $showPisRow);
        $shift = $showPisRow ? 0.0 : -6.5;
        $this->reformTaxes(183.2 + $shift);
        $this->totals(209.0 + $shift);
        $this->complement(222.7 + $shift);
    }

    private function person(string $type, string $title, float $y, bool $provider = false): void
    {
        $n = $this->note;
        if (!$n->dpsExists($type) && !$provider) { $this->emptyBlock($y, $title . ' DA OPERAÇÃO NÃO IDENTIFICADO NA NFS-e'); return; }
        $fallback = $provider && $n->dpsText('tpEmit') === '1';
        $value = function (string $field, ?string $emit = null) use ($n, $type, $fallback): string {
            $v = $n->dpsText($type . '/' . $field);
            return $v !== '' ? $v : ($fallback && $emit !== null ? $n->text('emit/' . $emit) : '');
        };
        $taxId = $value('CNPJ', 'CNPJ') ?: ($value('CPF', 'CPF') ?: $value('NIF'));
        $code = $value('end/endNac/cMun', 'enderNac/cMun');
        $zip = $value('end/endNac/CEP', 'enderNac/CEP') ?: $value('end/endExt/cEndPost');
        $place = $this->municipality($code, $fallback && $code === $n->dpsText('cLocEmi') ? $n->text('xLocEmi') : '');
        if ($place === '') { $place = self::join([$value('end/endExt/xCidade'), $value('end/endExt/xEstProvReg')]); }
        $address = self::join([$value('end/xLgr', 'enderNac/xLgr'), $value('end/nro', 'enderNac/nro'), $value('end/xCpl', 'enderNac/xCpl'), $value('end/xBairro', 'enderNac/xBairro')], ', ');
        $this->line($y);
        $this->blockTitle($y, $title);
        $this->field(54.1, $y, 50.9, 6.4, 'CNPJ / CPF / NIF', self::formatTaxId($taxId));
        $this->field(105.1, $y, 50.9, 6.4, 'Indicador Municipal (Inscrição)', $value('IM', 'IM'));
        $this->field(156.2, $y, 50.8, 6.4, 'Telefone', $value('fone', 'fone'));
        $this->field(3, $y + 6.4, 102, 6.4, 'Nome / Nome Empresarial', $value('xNome', 'xNome'));
        $this->field(105.1, $y + 6.4, 50.9, 6.4, 'Município / Sigla UF', $place);
        $this->field(156.2, $y + 6.4, 50.8, 6.4, 'Código IBGE / CEP', self::join([$code, $zip]));
        $this->field(3, $y + 13.0, 102, 6.4, 'Endereço', $address);
        $this->field(105.1, $y + 13.0, 101.9, 6.4, 'E-mail', $value('email', 'email'));
        if ($provider) {
            $this->field(3, $y + 19.4, 102, 6.4, 'Simples Nacional na Data de Competência', self::label($n->dpsText('prest/regTrib/opSimpNac'), ['1' => 'Não optante', '2' => 'Optante - MEI', '3' => 'Optante - ME/EPP']));
            $this->field(105.1, $y + 19.4, 101.9, 6.4, 'Regime de Apuração Tributária pelo SN', self::label($n->dpsText('prest/regTrib/regApTribSN'), ['1' => 'Tributos federais e municipal pelo Simples Nacional', '2' => 'Tributos federais pelo SN; ISSQN pelo regime regular', '3' => 'Tributos federais e municipal pelo regime regular']));
        }
    }

    private function municipalTaxes(float $y): void
    {
        $n = $this->note; $base = 'valores/trib/tribMun/';
        $this->line($y); $this->blockTitle($y, 'TRIBUTAÇÃO MUNICIPAL (ISSQN)');
        $this->field(54.1, $y, 50.9, 6.4, 'Tipo de Tributação do ISSQN', self::label($n->dpsText($base . 'tribISSQN'), ['1' => 'Operação tributável', '2' => 'Imunidade', '3' => 'Exportação de serviço', '4' => 'Não incidência']));
        $this->field(105.1, $y, 101.9, 6.4, 'Município / Sigla UF / País de Incidência do ISSQN', self::join([$this->municipality($n->text('cLocIncid'), $n->text('xLocIncid')), $n->dpsText($base . 'cPaisResult')]));
        $this->row($y + 6.5, [
            ['Regime Especial de Tributação do ISSQN', self::label($n->dpsText('prest/regTrib/regEspTrib'), ['0' => 'Nenhum', '1' => 'Ato cooperado', '2' => 'Estimativa', '3' => 'Microempresa municipal', '4' => 'Notário ou registrador', '5' => 'Profissional autônomo', '6' => 'Sociedade de profissionais', '9' => 'Outros'])],
            ['Tipo de Imunidade do ISSQN', self::label($n->dpsText($base . 'tpImunidade'), ['0' => 'Tipo não informado na origem', '1' => 'Recíproca (CF art. 150, VI, a)', '2' => 'Templos (CF art. 150, VI, b)', '3' => 'Entidades (CF art. 150, VI, c)', '4' => 'Livros e periódicos (CF art. 150, VI, d)', '5' => 'Fonogramas (CF art. 150, VI, e)'])],
            ['Suspensão da Exigibilidade do ISSQN', self::label($n->dpsText($base . 'exigSusp/tpSusp'), ['1' => 'Decisão judicial', '2' => 'Processo administrativo'])],
            ['Número Processo Suspensão', $n->dpsText($base . 'exigSusp/nProcesso')],
        ]);
        $this->row($y + 13.0, [
            ['Benefício Municipal', self::label($n->text('valores/tpBM'), ['1' => 'Isenção', '2' => 'Redução percentual da base', '3' => 'Redução monetária da base', '4' => 'Alíquota diferenciada'])],
            ['Cálculo do BM', self::money($n->text('valores/vCalcBM') ?: $n->dpsText($base . 'BM/vRedBCBM'))],
            ['Total Deduções / Reduções', self::money(self::sum([$n->dpsText('valores/vDedRed/vDR') ?: $n->text('valores/vCalcDR'), $n->text('IBSCBS/valores/vCalcReeRepRes')]))],
            ['Desconto Incondicionado', self::money($n->dpsText('valores/vDescCondIncond/vDescIncond'))],
        ]);
        $this->row($y + 19.4, [
            [$this->preview ? 'BC ISSQN (prévia)' : 'BC ISSQN', self::money($n->text($this->preview ? 'preview/iss_base' : 'valores/vBC'))],
            [$this->preview ? 'Alíquota Informada' : 'Alíquota Aplicada', self::percent($this->preview ? $n->dpsText('valores/trib/tribMun/pAliq') : $n->text('valores/pAliqAplic'))],
            ['Retenção do ISSQN', self::label($n->dpsText($base . 'tpRetISSQN'), ['1' => 'Não retido', '2' => 'Retido pelo tomador', '3' => 'Retido pelo intermediário'])],
            [$this->preview ? 'ISSQN calculado (prévia)' : 'ISSQN Apurado', self::money($n->text($this->preview ? 'preview/iss_amount' : 'valores/vISSQN'))],
        ]);
    }

    private function federalTaxes(float $y, bool $pisRow): void
    {
        $n = $this->note; $b = 'valores/trib/tribFed/'; $retained = $n->dpsText($b . 'piscofins/tpRetPisCofins');
        $social = $n->dpsText($b . 'vRetCSLL');
        if ($retained === '1') { $social = self::sum([$social, $n->dpsText($b . 'piscofins/vPis'), $n->dpsText($b . 'piscofins/vCofins')]); }
        $this->line($y); $this->blockTitle($y, 'TRIBUTAÇÃO FEDERAL (EXCETO CBS)');
        $this->field(54.1, $y, 50.9, 6.4, 'IRRF', self::money($n->dpsText($b . 'vRetIRRF')));
        $this->field(105.1, $y, 50.9, 6.4, 'Contribuição Previdenciária - Retida', self::money($n->dpsText($b . 'vRetCP')));
        $this->field(156.2, $y, 50.8, 6.4, 'Contribuições Sociais - Retidas', self::money($social));
        if ($pisRow) {
            $this->field(3, $y + 6.5, 51, 6.4, 'PIS - Débito Apuração Própria', self::money($retained === '1' ? '0.00' : $n->dpsText($b . 'piscofins/vPis')));
            $this->field(54.1, $y + 6.5, 50.9, 6.4, 'COFINS - Débito Apuração Própria', self::money($retained === '1' ? '0.00' : $n->dpsText($b . 'piscofins/vCofins')));
            $this->field(105.1, $y + 6.5, 101.9, 6.4, 'Descrição Contribuições Sociais - Retidas', self::label($retained, [
                '0' => 'PIS, COFINS e CSLL não retidos', '1' => 'PIS e COFINS retidos', '2' => 'PIS e COFINS não retidos',
                '3' => 'PIS, COFINS e CSLL retidos', '4' => 'PIS/COFINS retidos; CSLL não retida',
                '5' => 'PIS retido; COFINS/CSLL não retidas', '6' => 'COFINS retida; PIS/CSLL não retidos',
                '7' => 'COFINS/CSLL retidas; PIS não retido', '8' => 'CSLL retida; PIS/COFINS não retidos',
                '9' => 'PIS/CSLL retidos; COFINS não retida',
            ]));
        }
    }

    private function reformTaxes(float $y): void
    {
        $n = $this->note; $b = 'IBSCBS/';
        $this->line($y); $this->blockTitle($y, 'TRIBUTAÇÃO IBS / CBS');
        $this->field(54.1, $y, 50.9, 6.4, 'CST / cClassTrib', self::join([
            $n->dpsText($b . 'valores/trib/gIBSCBS/CST') ?: $n->dpsText($b . 'valores/trib/CST'),
            $n->dpsText($b . 'valores/trib/gIBSCBS/cClassTrib') ?: $n->dpsText($b . 'valores/trib/cClassTrib'),
        ]));
        $this->field(105.1, $y, 101.9, 6.4, 'Indicador de Operação / Código IBGE / Município / Sigla UF', self::join([$n->dpsText($b . 'cIndOp'), $n->text($b . 'cLocalidadeIncid'), $this->municipality($n->text($b . 'cLocalidadeIncid'), $n->text($b . 'xLocalidadeIncid'))]));
        $exclusions = $n->exists('IBSCBS') ? self::sum([$n->dpsText('valores/vDescCondIncond/vDescIncond'), $n->text($b . 'valores/vCalcReeRepRes'), $n->text('valores/vISSQN'), $n->dpsText('valores/trib/tribFed/piscofins/vPis'), $n->dpsText('valores/trib/tribFed/piscofins/vCofins')]) : '';
        $this->row($y + 6.4, [
            ['Exclusões e Reduções da Base de Cálculo', self::money($exclusions)], ['Base de Cálculo Após Exclusões e Reduções', self::money($n->text($b . 'valores/vBC'))],
            ['Red. Alíquotas IBS / Red. Alíquota CBS', self::join([self::percent($n->text($b . 'valores/uf/pRedAliqUF')), self::percent($n->text($b . 'valores/mun/pRedAliqMun')), self::percent($n->text($b . 'valores/fed/pRedAliqCBS'))])],
            ['Alíquota - IBS UF / IBS Mun', self::join([self::percent($n->text($b . 'valores/uf/pIBSUF')), self::percent($n->text($b . 'valores/mun/pIBSMun'))])],
        ]);
        $this->row($y + 12.9, [
            ['Alíquota Efetiva Municipal - IBS', self::percent($n->text($b . 'valores/mun/pAliqEfetMun'))], ['Valor Apurado Municipal - IBS', self::money($n->text($b . 'totCIBS/gIBS/gIBSMunTot/vIBSMun'))],
            ['Alíquota Efetiva Estadual - IBS', self::percent($n->text($b . 'valores/uf/pAliqEfetUF'))], ['Valor Apurado Estadual - IBS', self::money($n->text($b . 'totCIBS/gIBS/gIBSUFTot/vIBSUF'))],
        ]);
        $this->row($y + 19.4, [
            ['Valor Total Apurado - IBS', self::money($n->text($b . 'totCIBS/gIBS/vIBSTot'))], ['Alíquota - CBS', self::percent($n->text($b . 'valores/fed/pCBS'))],
            ['Alíquota Efetiva - CBS', self::percent($n->text($b . 'valores/fed/pAliqEfetCBS'))], ['Valor Total Apurado - CBS', self::money($n->text($b . 'totCIBS/gCBS/vCBS'))],
        ]);
    }

    private function totals(float $y): void
    {
        $n = $this->note;
        $this->line($y); $this->blockTitle($y, 'VALOR TOTAL DA NFS-E');
        $this->field(54.1, $y, 50.9, 6.7, 'VALOR DA OPERAÇÃO / SERVIÇO', self::money($n->dpsText('valores/vServPrest/vServ')));
        $this->field(105.1, $y, 50.9, 6.7, 'Desconto Incondicionado', self::money($n->dpsText('valores/vDescCondIncond/vDescIncond')));
        $this->field(156.2, $y, 50.8, 6.7, 'Desconto Condicionado', self::money($n->dpsText('valores/vDescCondIncond/vDescCond')));
        $this->row($y + 6.9, [
            ['Total das Retenções (ISSQN / Federais)', self::money($n->text('valores/vTotalRet'))], ['VALOR LÍQUIDO DA NFS-E', self::money($n->text('valores/vLiq'))],
            ['Total do IBS/CBS', self::money(self::sum([$n->text('IBSCBS/totCIBS/gIBS/vIBSTot'), $n->text('IBSCBS/totCIBS/gCBS/vCBS')]))],
            ['VALOR LÍQUIDO DA NFS-E + IBS/CBS', self::money($n->text('IBSCBS/totCIBS/vTotNF')), true],
        ], 6.7);
    }

    private function complement(float $y): void
    {
        $n = $this->note; $parts = [];
        if ($this->preview && $n->text('preview/ibpt_error') !== '') { $parts[] = $n->text('preview/ibpt_error'); }
        foreach (['serv/infoCompl/xInfComp' => 'Inf. Cont.', 'subst/chSubstda' => 'NFS-e Subst.', 'serv/infoCompl/docRef' => 'Doc. Ref.', 'serv/obra/cObra' => 'Cod. Obra', 'IBSCBS/imovel/inscImobFisc' => 'Insc. Imob.', 'serv/atvEvento/idAtvEvt' => 'Cod. Evt.', 'serv/infoCompl/idDocTec' => 'Doc. Tec.', 'serv/infoCompl/xPed' => 'Núm. Ped.'] as $path => $label) {
            $v = $n->dpsText($path); if ($v !== '') { $parts[] = $label . ': ' . $v; }
        }
        if ($n->text('xOutInf') !== '') { $parts[] = 'Inf. A. T. Mun.: ' . $n->text('xOutInf'); }
        $simple = $n->dpsText('valores/trib/totTrib/pTotTribSN');
        if ($simple !== '') { $parts[] = 'Tributos aproximados pelo Simples Nacional: ' . self::percent($simple); }
        $taxParts = [];
        foreach (['Fed' => 'Federais', 'Est' => 'Estaduais', 'Mun' => 'Municipais'] as $suffix => $label) {
            $amount = $n->dpsText('valores/trib/totTrib/vTotTrib/vTotTrib' . $suffix);
            $percent = $n->dpsText('valores/trib/totTrib/pTotTrib/pTotTrib' . $suffix);
            $taxParts[] = $label . ': ' . ($amount !== '' ? self::money($amount) : ($percent !== '' ? self::percent($percent) : '-'));
        }
        $this->line($y);
        $this->pdf->Rect(3, $y, 204, 3.9, 'F');
        $this->plain(3.8, $y + 0.7, 202.4, 2.7, 'INFORMAÇÕES COMPLEMENTARES', 7, true);
        $this->plain(3.8, $y + 4.2, 202.4, 261 - ($y + 4.2), implode(' | ', $parts), 7);
        $this->transmissionPanel();
        $this->plain(3.8, 287.0, 202.4, 6.5, 'Totais Aproximados dos Tributos cfe. Lei nº 12.741/2012: ' . implode('; ', $taxParts) . '.', 7);
    }

    private function stamp(): string
    {
        $status = $this->fiscalStatus['status'];
        if ($status === 'cancelled') { return 'CANCELADA'; }
        if ($status === 'authorized') { return 'TRANSMITIDA E AUTORIZADA'; }
        if (in_array($status, ['processing', 'uncertain'], true)) { return 'AGUARDANDO CONFIRMAÇÃO'; }
        if ($status === 'rejected') { return 'REJEITADA'; }
        if ((int) ($this->document['attempts'] ?? 0) > 0) { return 'NÃO AUTORIZADA'; }
        return $status === 'draft' ? 'RASCUNHO' : 'NÃO TRANSMITIDA';
    }

    private function transmissionPanel(): void
    {
        $status = $this->fiscalStatus['status'];
        $this->line(263.2);
        $this->pdf->Rect(3, 263.2, 204, 3.9, 'F');
        $this->plain(3.8, 263.8, 202.4, 2.8, 'SITUAÇÃO DA TRANSMISSÃO', 7, true);
        if ($this->preview) {
            $attempts = (int) ($this->document['attempts'] ?? 0);
            $transmission = in_array($status, ['processing', 'uncertain'], true)
                ? 'Transmissão tentada; autorização ainda não confirmada. Consulte o resultado antes de reenviar.'
                : ($status === 'rejected' ? 'Solicitação rejeitada. Não há NFS-e autorizada para este documento.'
                    : ($attempts > 0 ? 'Houve tentativa anterior. Esta versão local ainda não foi enviada; não há autorização confirmada.'
                        : 'Não transmitida ao Sistema Nacional da NFS-e.'));
            $this->plain(3.8, 267.6, 202.4, 3.1, $this->stamp() . ' - SEM VALIDADE FISCAL', 7, true);
            $this->plain(3.8, 271.1, 202.4, 3.1, $transmission, 7);
            $this->plain(3.8, 274.6, 202.4, 3.1, 'Sem número, chave ou protocolo de autorização. Campos ausentes ou inválidos permanecem em branco.', 7);
            $this->plain(3.8, 278.1, 202.4, 3.1, 'Valores e tributos sujeitos à validação e ao processamento fiscal. Esta visualização não transmite o documento.', 7);
            $identifier = (int) ($this->document['id'] ?? 0);
            $this->plain(3.8, 281.6, 202.4, 3.1, ($identifier > 0 ? 'Documento interno #' . $identifier . '. ' : '')
                . 'Prévia dos dados salvos. Revise o tomador, a descrição e o perfil de emissão antes de transmitir.', 7);
            return;
        }
        $environment = $this->note->environment() === '2' ? ' (homologação, sem validade jurídica)' : '';
        $this->plain(3.8, 267.6, 202.4, 3.1, $this->stamp() . $environment, 7, true);
        $processed = self::date($this->note->text('dhProc'));
        $this->plain(3.8, 271.1, 202.4, 3.1, 'Processamento no Sistema Nacional: ' . $processed, 7);
        $protocol = self::metadata($this->fiscalStatus, 'protocol');
        $this->plain(3.8, 274.6, 202.4, 3.1, 'Protocolo de autorização: ' . ($protocol !== '' ? $protocol : 'não fornecido; identificação pela chave de acesso e pelo número da NFS-e.'), 7);
        if ($status === 'cancelled') {
            $cancellation = self::metadata($this->fiscalStatus, 'cancellation_event_id');
            $this->plain(3.8, 278.1, 202.4, 3.1, 'Evento de cancelamento: ' . ($cancellation !== '' ? $cancellation : 'confirmado pelo emissor nacional'), 7);
            $this->plain(3.8, 281.6, 202.4, 3.1, 'Cancelamento processado em: ' . (self::date(self::metadata($this->fiscalStatus, 'cancelled_at')) ?: 'data não informada'), 7);
        } else {
            $checked = self::date(self::metadata($this->fiscalStatus, 'checked_at'));
            $this->plain(3.8, 278.1, 202.4, 3.1, 'DPS vinculada: ' . $this->note->dpsId(), 7);
            $this->plain(3.8, 281.6, 202.4, 3.1, $checked !== '' ? 'Última consulta da situação fiscal: ' . $checked : 'Situação confirmada no recebimento do XML. Consulte o emissor nacional para verificar eventos posteriores.', 7);
        }
    }

    private static function metadata(array $metadata, string $key): string
    {
        return isset($metadata[$key]) && is_scalar($metadata[$key]) ? trim((string) $metadata[$key]) : '';
    }

    private function row(float $y, array $fields, float $height = 6.4): void
    {
        foreach ($fields as $i => $f) { $this->field([3, 54.1, 105.1, 156.2][$i], $y, $i === 0 ? 51 : ($i === 3 ? 50.8 : 50.9), $height, $f[0], $f[1], $f[2] ?? false); }
    }

    private function field(float $x, float $y, float $w, float $h, string $label, string $value, bool $fill = false, bool $identity = false): void
    {
        if ($fill) { $this->pdf->Rect($x, $y, $w, $h, 'F'); }
        $this->plain($x + 0.8, $y + 0.25, $w - 1.6, 2.7, $label, $identity ? 7 : 6, true);
        $this->plain($x + 0.8, $y + 3.1, $w - 1.6, max(2.7, $h - 3.2), $value !== '' ? $value : '-', 7);
    }

    private function blockTitle(float $y, string $title): void
    {
        $this->pdf->Rect(3, $y, 51, 6.4, 'F');
        $this->plain(3.8, $y + 0.5, 49.4, 5.2, $title, 7, true);
    }
    private function emptyBlock(float $y, string $label): void { $this->line($y); $this->plain(3.8, $y + 0.4, 202.4, 2.8, $label, 7, true); }
    private function line(float $y): void { $this->pdf->Line(3, $y, 207, $y); }

    private function plain(float $x, float $y, float $w, float $h, string $text, float $size, bool $bold = false, string $align = 'L'): void
    {
        $this->pdf->SetFont($bold ? $this->labelFont : $this->contentFont, $bold ? 'B' : '', $size);
        $h = max($h, $size * 25.4 / 72 * 1.05 + 0.1);
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $text) ?? '';
        if ($text === '') { $text = '-'; }
        if ($this->pdf->getStringHeight($w, $text, false, false, 0) > $h) {
            $low = 0; $high = mb_strlen($text);
            while ($low < $high) {
                $mid = (int) ceil(($low + $high) / 2);
                $candidate = mb_substr($text, 0, $mid) . '...';
                if ($this->pdf->getStringHeight($w, $candidate, false, false, 0) <= $h) { $low = $mid; } else { $high = $mid - 1; }
            }
            $text = rtrim(mb_substr($text, 0, $low)) . '...';
        }
        $this->pdf->SetXY($x, $y);
        $this->pdf->MultiCell($w, $h, $text, 0, $align, false, 0, $x, $y, true, 0, false, false, $h, 'T', false);
    }

    private function municipality(string $code, string $xmlName = ''): string
    {
        if ($code === '') { return $xmlName; }
        if (self::$municipalities === null) {
            $path = dirname(__DIR__, 2) . '/resources/municipios.json';
            self::$municipalities = is_file($path) ? (json_decode((string) file_get_contents($path), true) ?: []) : [];
        }
        $entry = self::$municipalities[$code] ?? [];
        return self::join([$xmlName !== '' ? $xmlName : ($entry['name'] ?? $code), $entry['uf'] ?? '']);
    }

    private static function label(string $value, array $labels): string { return $value === '' ? '' : ($labels[$value] ?? $value); }
    private static function join(array $values, string $separator = ' / '): string { return implode($separator, array_filter($values, static fn($v) => $v !== '')); }
    private static function date(string $value): string
    {
        if ($value === '') { return ''; }
        try { return (new \DateTimeImmutable($value))->format(strlen($value) > 10 ? 'd/m/Y H:i:s' : 'd/m/Y'); }
        catch (\Throwable $error) { return $value; }
    }
    private static function numberFormat(string $value): string
    {
        $normalized = Decimal::normalize($value);
        [$integer, $fraction] = explode('.', $normalized);
        return preg_replace('/\B(?=(\d{3})+(?!\d))/', '.', $integer) . ',' . $fraction;
    }
    private static function money(string $value): string { return $value === '' ? '' : 'R$ ' . self::numberFormat($value); }
    private static function percent(string $value): string { return $value === '' ? '' : self::numberFormat($value) . '%'; }
    private static function sum(array $values): string
    {
        $present = array_filter($values, static fn($v) => $v !== '');
        if ($present === []) { return ''; }
        $total = 0; foreach ($present as $v) { $total += Decimal::minor($v); }
        return Decimal::fromMinor($total);
    }
    private static function formatTaxId(string $id): string
    {
        if (strlen($id) === 14) { return substr($id, 0, 2) . '.' . substr($id, 2, 3) . '.' . substr($id, 5, 3) . '/' . substr($id, 8, 4) . '-' . substr($id, 12); }
        if (strlen($id) === 11) { return substr($id, 0, 3) . '.' . substr($id, 3, 3) . '.' . substr($id, 6, 3) . '-' . substr($id, 9); }
        return $id;
    }
}
