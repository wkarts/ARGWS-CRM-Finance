<?php
declare(strict_types=1);

namespace NfseNacional;

/** A replacement is a new, reviewed DPS. The authorized source is never edited. */
final class Substitution
{
    public static function reasons(): array
    {
        return ['01' => 'Desenquadramento do Simples Nacional', '02' => 'Enquadramento no Simples Nacional',
            '03' => 'Inclusão retroativa de imunidade ou isenção', '04' => 'Exclusão retroativa de imunidade ou isenção',
            '05' => 'Rejeição pelo tomador ou intermediário responsável pelo tributo', '99' => 'Outros'];
    }

    public static function parameters($input): array
    {
        if (!is_array($input)) { throw new \InvalidArgumentException('Dados da substituição inválidos.'); }
        $key = Config::text($input['access_key'] ?? '', 50);
        $code = Config::text($input['reason_code'] ?? '', 2);
        $reason = Config::text($input['reason'] ?? '', 255);
        if (!preg_match('/^[0-9A-Z]{50}$/D', $key) || !isset(self::reasons()[$code])) {
            throw new \InvalidArgumentException('Selecione o motivo e a NFS-e original para substituir.');
        }
        if (mb_strlen($reason, 'UTF-8') < 15) { throw new \InvalidArgumentException('Descreva a justificativa da substituição com 15 a 255 caracteres.'); }
        return ['access_key' => $key, 'reason_code' => $code, 'reason' => $reason];
    }

    public static function prepare($model, int $id, string $code, string $reason): int
    {
        if (!nfse_nacional_can('substitute') || !nfse_nacional_can('create') || !nfse_nacional_can('edit')) {
            throw new \RuntimeException('Você não tem permissão para preparar substituições.');
        }
        $original = $model->document($id);
        return $model->lock('invoice:' . (int) $original['invoice_id'], function () use ($model, $id, $code, $reason): int {
            $original = $model->document($id);
            $existing = $model->replacementFor($id);
            if ($existing) { return (int) $existing['id']; }
            if ($original['status'] !== 'authorized') { throw new \RuntimeException('Somente uma NFS-e autorizada pode originar a substituição.'); }
            $service = new Service($model);
            if (!$service->fiscalStatus($original)['is_authorized']) { throw new \RuntimeException('Confirme a autorização da nota original antes de substituir.'); }
            $note = new NfseDocument($service->xml($id));
            $draft = \Nfse_nacional_model::decode($original['draft_json']);
            $issuer = Config::taxId((string) ($model->settings()['provider']['tax_id'] ?? ''));
            if ($issuer === '' || $issuer !== $draft['provider']['tax_id'] || $issuer !== NationalRepository::noteTaxId($note, 'prest')) {
                throw new \RuntimeException('A nota original não corresponde ao emitente configurado nesta empresa.');
            }
            CompanyContext::current()->assertIssuer($issuer);
            $invoice = $model->invoice((int) $original['invoice_id']); $model->assertInvoice($invoice);
            if ((int) $invoice->clientid !== (int) $original['client_id']) { throw new \RuntimeException('O vínculo da fatura com o cliente foi alterado.'); }
            $draft['substitution'] = self::parameters(['access_key' => $note->accessKey(), 'reason_code' => $code, 'reason' => $reason]);
            $draft['environment'] = $original['environment'];
            $draft['invoice_values_acknowledged'] = '0';
            unset($draft['issued_at']);
            return $model->createSubstitution($original, $draft, $invoice);
        });
    }

    /** Recheck at transmission, since a draft can remain open while the source changes. */
    public static function assertSource($model, array $document, array $draft): void
    {
        $parent = (int) ($document['replacement_of_id'] ?? 0);
        if ($parent === 0 && empty($draft['substitution'])) { return; }
        if ($parent < 1 || !nfse_nacional_can('substitute')) { throw new \RuntimeException('Substituição sem vínculo fiscal ou sem permissão.'); }
        $parameters = self::parameters($draft['substitution'] ?? null);
        $source = $model->document($parent);
        $sourceDraft = \Nfse_nacional_model::decode($source['draft_json']);
        if ($source['status'] !== 'authorized' || $source['environment'] !== $document['environment']
            || (int) $source['invoice_id'] !== (int) $document['invoice_id'] || (int) $source['client_id'] !== (int) $document['client_id']
            || ($source['access_key'] ?? '') !== $parameters['access_key']
            || ($draft['provider']['tax_id'] ?? '') !== ($sourceDraft['provider']['tax_id'] ?? '')) {
            throw new \RuntimeException('A nota original mudou ou não está disponível para substituição. Consulte sua situação.');
        }
        $sourceStatus = (new Service($model))->fiscalStatus($source);
        if (!$sourceStatus['is_authorized']) { throw new \RuntimeException('Não há XML autorizado da nota original para confirmar a substituição.'); }
        $note = new NfseDocument($model->crypto()->decrypt($source['response_enc']));
        self::assertAllowedChanges($note, $draft, $parameters['reason_code']);
        $cancellation = (new FiscalRequestRepository($model))->find((string) $draft['provider']['tax_id'], $source['environment'], $source['access_key'], '101101');
        if ($cancellation && in_array($cancellation['status'], ['processing', 'uncertain', 'registered'], true)) {
            throw new \RuntimeException('Existe um pedido de cancelamento confirmado ou em consulta. Confira esse evento antes de substituir a nota.');
        }
    }

    /** Fixed restrictions documented in the national FAQ; municipal eligibility remains with SEFIN. */
    public static function assertAllowedChanges(NfseDocument $source, array $draft, string $reasonCode): void
    {
        if (NationalRepository::noteTaxId($source, 'toma') !== Config::taxId((string) ($draft['recipient']['tax_id'] ?? ''))) {
            throw new \InvalidArgumentException('A substituição não permite trocar a identificação do tomador (regra E0058). Consulte o cancelamento ou a análise fiscal com o município.');
        }
        if ($source->dpsText('dCompet') !== (string) ($draft['competence'] ?? '')) {
            throw new \InvalidArgumentException('A data da competência precisa permanecer igual à da NFS-e original na substituição.');
        }
        // A change of Simples status has specific national handling; do not infer
        // a municipal waiver or apply the new regime to a historical source.
        if (in_array($reasonCode, ['01', '02'], true)) { return; }
        $simple = $source->dpsText('prest/regTrib/opSimpNac');
        if ($simple === '1') {
            if (substr($source->dpsText('serv/cServ/cTribNac'), 0, 4) !== substr((string) ($draft['service']['national_code'] ?? ''), 0, 4)
                || $source->dpsText('serv/cServ/cTribMun') !== (string) ($draft['service']['municipal_code'] ?? '')
                || $source->dpsText('serv/locPrest/cLocPrestacao') !== (string) ($draft['service']['municipality_code'] ?? '')) {
                throw new \InvalidArgumentException('No regime normal, a substituição deve preservar o subitem do serviço, o código municipal e o local da prestação (E0060).');
            }
        } elseif (in_array($simple, ['2', '3'], true)
            && Decimal::normalize($source->dpsText('valores/vServPrest/vServ')) !== Decimal::normalize($draft['values']['service_amount'] ?? '')) {
            throw new \InvalidArgumentException('Para a nota do Simples Nacional, a substituição deve preservar o valor dos serviços (E0061).');
        }
    }
}
