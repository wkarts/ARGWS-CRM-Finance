<?php
declare(strict_types=1);
namespace NfseNacional;

/** Application service. A stable DPS identity is persisted before any remote write. */
final class Service
{
    private $model;
    private $apiFactory;
    private $ibptLookup;

    public function __construct($model, ?callable $apiFactory = null, ?callable $ibptLookup = null)
    {
        $this->model = $model;
        $this->apiFactory = $apiFactory ?? static fn(string $environment, Certificate $certificate) => new ApiClient($environment, $certificate);
        $this->ibptLookup = $ibptLookup;
    }

    public function certificate(?string $expectedTaxId = null, ?string $environment = null): Certificate
    {
        return (new CertificateResolver($this->model))->resolve($expectedTaxId, $environment);
    }

    public function saveDraft(int $id, array $input, string $expectedHash, bool $sendAfter, ?int $recipientId = null, ?string $recipientSelection = null): array
    {
        return $this->model->lock('document:' . $id, function () use ($id, $input, $expectedHash, $sendAfter, $recipientId, $recipientSelection) {
            $doc = $this->model->document($id);
            $this->editable($doc);
            if ($expectedHash === '' || !hash_equals($doc['draft_hash'], $expectedHash)) { throw new \RuntimeException('O rascunho foi alterado em outra janela. Atualize a página antes de salvar.'); }
            if ($sendAfter !== (bool) $doc['send_after_authorized'] && !nfse_nacional_can('send')) { throw new \RuntimeException('Você não tem permissão para alterar o envio ao destinatário.'); }
            $d = \Nfse_nacional_model::decode($doc['draft_json']);
            $invoice = $this->model->invoice((int) $doc['invoice_id']);
            $this->model->assertInvoice($invoice);
            if ((int) $invoice->clientid !== (int) $doc['client_id']) { throw new ValidationException(['O cliente da fatura foi alterado. Este rascunho não pode ser modificado.']); }
            $fingerprint = \Nfse_nacional_model::fingerprint($invoice);
            if (!hash_equals($doc['invoice_fingerprint'], $fingerprint) && ($input['acknowledge_invoice_values'] ?? '') !== '1') {
                throw new ValidationException(['A fatura mudou desde a preparação. Atualize a tela, confira os valores atuais e marque a confirmação antes de salvar.']);
            }
            if (($input['acknowledge_invoice_values'] ?? '') === '1') { $d['invoice_values_acknowledged'] = '1'; }
            $d['competence'] = Config::text($input['competence'] ?? $d['competence'], 10);
            $selectedProfile = null;
            if ($recipientSelection !== null && $recipientSelection !== '') {
                $selected = $this->model->recipientSelectionData((int) $doc['client_id'], $recipientSelection);
                if (($d['recipient_selection'] ?? '') !== $selected['selection']) {
                    $d['recipient'] = $selected['recipient'];
                    $d['recipient_selection'] = $selected['selection'];
                    $selectedProfile = EmissionProfile::resolve($this->model->settings(), $selected['profile'], $selected['recipient_defaults'], $invoice->items ?? []);
                }
            } elseif ($recipientId !== null && $recipientId > 0) {
                $selected = $this->model->recipient($recipientId, (int) $doc['client_id']);
                if (($d['recipient_selection'] ?? '') !== 'recipient:' . $recipientId) {
                    $d['recipient'] = \Nfse_nacional_model::sanitizeRecipient($selected);
                    $d['recipient_selection'] = 'recipient:' . $recipientId;
                    $selectedProfile = EmissionProfile::resolve($this->model->settings(), $this->model->profile((int) $doc['client_id']), $selected, $invoice->items ?? []);
                }
            }
            if ($selectedProfile !== null) {
                // A real recipient change replaces every fiscal default, including explicit zero/omission.
                // Invoice amounts and the operator's following visible edits retain their own precedence.
                $d['service'] = $selectedProfile['service'];
                $d['values'] = array_replace($d['values'], $selectedProfile['tax']);
                $d['ibscbs'] = $selectedProfile['ibscbs'];
            }
            if (isset($input['recipient'])) {
                if (!is_array($input['recipient'])) { throw new \InvalidArgumentException('Dados do tomador inválidos.'); }
                // The selected record is a default, while visible edits belong to this note only.
                $d['recipient'] = \Nfse_nacional_model::sanitizeRecipient(array_replace($d['recipient'], $input['recipient']));
            }
            foreach (['service', 'values', 'ibscbs'] as $group) {
                if (isset($input[$group]) && !is_array($input[$group])) { throw new \InvalidArgumentException('Dados fiscais inválidos.'); }
                foreach ($d[$group] as $key => $value) {
                    if (array_key_exists($key, $input[$group] ?? [])) { $d[$group][$key] = Config::text($input[$group][$key], $key === 'description' ? 2000 : 200); }
                }
            }
            $d['service']['national_code'] = Config::serviceCode($d['service']['national_code'] ?? '');
            if (isset($input['substitution'])) {
                if (empty($d['substitution']) || !is_array($input['substitution'])) { throw new \InvalidArgumentException('Este documento não possui uma substituição preparada.'); }
                $d['substitution'] = Substitution::parameters(array_replace($d['substitution'],
                    array_intersect_key($input['substitution'], array_flip(['reason_code', 'reason']))));
            }
            foreach (['service_amount', 'unconditional_discount', 'conditional_discount', 'total_federal', 'total_state', 'total_municipal'] as $key) {
                $d['values'][$key] = Decimal::normalize($d['values'][$key] ?? '0');
            }
            foreach (['iss_rate', 'simple_rate'] as $key) {
                if (($d['values'][$key] ?? '') !== '') { $d['values'][$key] = Decimal::normalize($d['values'][$key]); }
            }
            // The issuer identity cannot change after a DPS has been reserved.
            if (empty($doc['dps_id'])) { $d['provider'] = $this->model->settings()['provider']; }
            $d = $this->model->applyCompetenceRate($d);
            $d = DraftTax::refresh($d, $this->ibptLookup);
            $json = \Nfse_nacional_model::json($d);
            $this->model->updateDocument($id, ['draft_json' => $json, 'draft_hash' => hash('sha256', $json),
                'status' => 'draft', 'last_error' => null, 'request_enc' => null,
                'service_amount' => Decimal::subtract($d['values']['service_amount'], $d['values']['unconditional_discount']),
                'send_after_authorized' => $sendAfter ? 1 : 0, 'recipient_email' => $d['recipient']['email'],
                // Save constitutes a fresh review of the current invoice; the fiscal value remains explicit.
                'invoice_fingerprint' => $fingerprint]);
            $this->model->event($id, 'draft_saved', 'Rascunho revisado e salvo. A validação será refeita antes da transmissão.');
            return $this->publicDocument($this->model->document($id));
        });
    }

    private function editable(array $doc): void
    {
        if (!in_array($doc['status'], ['draft', 'ready', 'rejected'], true)) { throw new \RuntimeException('Esta nota não pode ser editada. Consulte a situação da DPS.'); }
    }

    private function validateDocument(array $doc): array
    {
        $d = \Nfse_nacional_model::decode($doc['draft_json']);
        $invoice = $this->model->invoice((int) $doc['invoice_id']);
        $this->model->assertInvoice($invoice);
        if (!hash_equals($doc['invoice_fingerprint'], \Nfse_nacional_model::fingerprint($invoice))) {
            throw new ValidationException(['A fatura foi alterada após preparar o rascunho. Revise os valores e salve o rascunho novamente.']);
        }
        if ((int) $invoice->clientid !== (int) $doc['client_id']) { throw new ValidationException(['O cliente da fatura foi alterado. Esta DPS não pode ser transmitida.']); }
        $invoiceNet = Decimal::subtract($invoice->subtotal, $invoice->discount_total ?? '0');
        if (Decimal::normalize($invoice->total) !== $invoiceNet && ($d['invoice_values_acknowledged'] ?? '0') !== '1') {
            throw new ValidationException(['A fatura possui tributos, acréscimos ou ajustes além dos serviços. Confira a base fiscal no rascunho e marque a confirmação dos valores.']);
        }
        // Resolve the document's competence at the final gate, including old drafts.
        if (empty($doc['dps_id'])) { $d = $this->model->applyCompetenceRate($d, true); }
        elseif (CompetenceRate::enabled($this->model->settings())) {
            // A reserved/signed DPS is immutable. Reconcile instead of changing its tax payload.
            $this->model->applyCompetenceRate($d, true);
            CompetenceRate::assertSnapshots($d, $this->model->settings());
        }
        $errors = Validator::draft($d);
        Substitution::assertSource($this->model, $doc, $d);
        if ($errors) { throw new ValidationException($errors); }
        $cert = $this->certificate($d['provider']['tax_id'], (string) $doc['environment']);
        $d['issued_at'] = $d['issued_at'] ?? (new \DateTimeImmutable('now', new \DateTimeZone('America/Sao_Paulo')))->format('Y-m-d\TH:i:sP');
        $settings = $this->model->settings();
        $builder = new DpsBuilder();
        $xml = $builder->build($d, (string) ($doc['dps_series'] ?: $settings['series']), (string) ($doc['dps_number'] ?: '1'));
        $signed = XmlSigner::sign($xml, $cert);
        $errors = $builder->validateSchema($signed, $doc['environment']);
        if ($errors) { throw new ValidationException($errors); }
        if (!XmlSigner::verify($signed)) { throw new \RuntimeException('A conferência local da assinatura digital falhou.'); }
        return [$d, $cert];
    }

    public function validate(int $id): array
    {
        return $this->model->lock('document:' . $id, function () use ($id) {
            $doc = $this->model->document($id); $this->editable($doc);
            try { [$validatedDraft] = $this->validateDocument($doc); }
            catch (ValidationException $e) {
                $this->model->updateDocument($id, ['status' => 'draft', 'last_error' => mb_substr($e->getMessage(), 0, 4000)]);
                throw $e;
            }
            $changes = ['status' => 'ready', 'last_error' => null];
            if (empty($doc['dps_id']) && CompetenceRate::enabled($this->model->settings())) {
                $json = \Nfse_nacional_model::json($validatedDraft);
                $changes += ['draft_json' => $json, 'draft_hash' => hash('sha256', $json)];
            }
            $this->model->updateDocument($id, $changes);
            $this->model->event($id, 'validated', 'Dados, esquema XML e assinatura conferidos localmente. A autorização depende das regras do ambiente nacional.');
            return $this->publicDocument($this->model->document($id));
        });
    }

    private function emissionLock(int $id, callable $operation)
    {
        $snapshot = $this->model->document($id);
        $parentId = (int) ($snapshot['replacement_of_id'] ?? 0);
        if ($parentId > 0) {
            $parent = $this->model->document($parentId);
            // Cancellation and replacement of the same source cannot race in this installation.
            return $this->model->lock('fiscal:' . $parent['environment'] . ':' . $parent['access_key'],
                fn() => $this->model->lock('document:' . $id, $operation));
        }
        return $this->model->lock('invoice:' . $snapshot['invoice_id'], fn() => $this->model->lock('document:' . $id, $operation));
    }

    public function transmit(int $id): array
    {
        $result = $this->emissionLock($id, function () use ($id) {
            $doc = $this->model->document($id);
            if ($doc['status'] === 'authorized') { return $this->publicDocument($doc); }
            if (in_array($doc['status'], ['uncertain', 'processing'], true)) { return $this->reconcileLocked($doc); }
            $this->editable($doc);
            $settings = $this->model->settings();
            if ($doc['environment'] === '1' && $settings['production_enabled'] !== '1') { throw new \RuntimeException('Habilite a emissão em produção nas configurações da empresa.'); }
            [$draft, $certificate] = $this->validateDocument($doc);
            $api = ($this->apiFactory)($doc['environment'], $certificate);
            // A definitive rejection can be corrected, but the original DPS is always checked first.
            if (!empty($doc['dps_id']) && (int) $doc['attempts'] > 0) {
                $found = $api->findByDps($doc['dps_id']);
                if ($found) { return $this->authorize($doc, $this->resolveResponse($api, $found), 'dps'); }
            }
            $doc = $this->model->reserve($doc, $draft, $settings);
            $draft = \Nfse_nacional_model::decode($doc['draft_json']);
            $builder = new DpsBuilder();
            $signed = XmlSigner::sign($builder->build($draft, $doc['dps_series'], $doc['dps_number']), $certificate);
            $errors = $builder->validateSchema($signed, $doc['environment']);
            if ($errors) { throw new ValidationException($errors); }
            return $this->submitPersisted($doc, $signed, $api);
        });
        return $this->deliverIfRequested($id, $result);
    }

    /** Explicit recovery only: query first, then resend the identical signed XML and DPS identity. */
    public function retrySame(int $id): array
    {
        $result = $this->emissionLock($id, function () use ($id) {
            $doc = $this->model->document($id);
            if ($doc['status'] === 'authorized') { return $this->publicDocument($doc); }
            if (!in_array($doc['status'], ['uncertain', 'processing'], true) || empty($doc['request_enc']) || empty($doc['dps_id'])) {
                throw new \RuntimeException('Não há uma transmissão pendente para reenviar.');
            }
            if ($doc['environment'] === '1' && $this->model->settings()['production_enabled'] !== '1') { throw new \RuntimeException('A emissão em produção está desabilitada.'); }
            $draft = \Nfse_nacional_model::decode($doc['draft_json']);
            $api = ($this->apiFactory)($doc['environment'], $this->certificate($draft['provider']['tax_id'], (string) $doc['environment']));
            $found = $api->findByDps($doc['dps_id']);
            if ($found) { return $this->authorize($doc, $this->resolveResponse($api, $found), 'dps'); }
            // Recovery by GET remains available, but a fresh POST must still be
            // allowed for this replacement and its original fiscal document.
            Substitution::assertSource($this->model, $doc, $draft);
            $signed = $this->model->crypto()->decrypt($doc['request_enc']);
            $xml = XmlSigner::loadSafe($signed); $xp = new \DOMXPath($xml); $xp->registerNamespace('n', XmlSigner::NFSE_NS);
            if (!XmlSigner::verify($signed) || $xp->evaluate('string(/n:DPS/n:infDPS/@Id)') !== $doc['dps_id']
                || $xp->evaluate('string(/n:DPS/n:infDPS/n:tpAmb)') !== $doc['environment']) {
                throw new \RuntimeException('A DPS armazenada não passou na conferência. Restaure o documento a partir do backup.');
            }
            $this->model->event($id, 'retry_requested', 'Reenvio explícito solicitado após consulta sem nota localizada; XML, série e número originais preservados.');
            return $this->submitPersisted($doc, $signed, $api);
        });
        return $this->deliverIfRequested($id, $result);
    }

    private function submitPersisted(array $doc, string $signed, $api): array
    {
        $id = (int) $doc['id'];
        $invoice = $this->model->invoice((int) $doc['invoice_id']);
        $this->model->assertInvoice($invoice);
        if ((int) $invoice->clientid !== (int) $doc['client_id'] || !hash_equals($doc['invoice_fingerprint'], \Nfse_nacional_model::fingerprint($invoice))) {
            throw new ValidationException(['A fatura foi alterada antes da transmissão. Revise o documento.']);
        }
        $meta = FiscalStatus::metadata($doc);
        $meta['last_submitted_at'] = \Nfse_nacional_model::now();
        $this->model->updateDocument($id, ['request_enc' => $this->model->crypto()->encrypt($signed), 'status' => 'processing',
            'attempts' => (int) $doc['attempts'] + 1, 'last_error' => null,
            'fiscal_meta_json' => \Nfse_nacional_model::json($meta)]);
        $this->model->event($id, 'transmission_started', 'DPS reservada e persistida; transmissão iniciada.');
        try { return $this->authorize($doc, $api->submit($signed)); }
        catch (ApiException $error) {
            // Even a duplicate validation response can refer to an earlier successful attempt.
            if (!$error->isUncertain()) {
                try {
                    $found = $api->findByDps($doc['dps_id']);
                    if ($found) { return $this->authorize($doc, $this->resolveResponse($api, $found), 'dps'); }
                } catch (\Throwable $lookupError) {
                    // A failed or inconsistent lookup cannot prove that this DPS is unused.
                    // Keep it locked for reconciliation, including after a duplicate rejection.
                    $error = ($lookupError instanceof ApiException && $lookupError->isUncertain())
                        ? $lookupError
                        : new ApiException('A consulta posterior não confirmou a situação da DPS. Consulte novamente antes de alterar ou reenviar.', 0, true, [], $lookupError);
                }
            }
            $state = $error->isUncertain() ? 'uncertain' : 'rejected';
            $this->model->updateDocument($id, ['status' => $state, 'last_error' => mb_substr($error->getMessage(), 0, 4000)]);
            $this->model->event($id, $state, $state === 'uncertain' ? 'Resultado não confirmado. Consulte a DPS; nenhuma nova numeração será gerada.' : 'A API rejeitou a DPS. Confira os motivos, corrija e valide novamente.');
            throw $error;
        } catch (\Throwable $error) {
            $this->model->updateDocument($id, ['status' => 'uncertain', 'last_error' => 'O resultado não pôde ser confirmado. Consulte a DPS já enviada.']);
            throw new \RuntimeException('O resultado da transmissão não pôde ser confirmado. Use Consultar DPS; a numeração foi preservada.', 0, $error);
        }
    }

    private function deliverIfRequested(int $id, array $result): array
    {
        if ($result['status'] === 'authorized' && !empty($result['send_after_authorized']) && nfse_nacional_can('send')) {
            try { $result = $this->send($id); }
            catch (\Throwable $error) { $result['delivery_warning'] = 'A NFS-e foi autorizada. O envio do e-mail não foi concluído: ' . $error->getMessage(); }
        }
        return $result;
    }

    private function resolveResponse($api, array $response): array
    {
        if (!empty($response['nfseXmlGZipB64'])) { return $response; }
        $key = (string) ($response['chaveAcesso'] ?? '');
        if (!preg_match('/^[A-Z0-9]{50}$/D', $key)) { throw new \RuntimeException('A consulta retornou uma chave de NFS-e inválida.'); }
        return $api->fetch($key) + ['chaveAcesso' => $key];
    }

    public function reconcile(int $id): array
    {
        $alreadyOfficial = false;
        $result = $this->emissionLock($id, function () use ($id, &$alreadyOfficial) {
            $doc = $this->model->document($id);
            $alreadyOfficial = in_array($doc['status'], ['authorized', 'cancelled'], true);
            return $this->reconcileLocked($doc);
        });
        // A read-only status refresh must not resend a message to the customer.
        return $alreadyOfficial ? $result : $this->deliverIfRequested($id, $result);
    }

    private function reconcileLocked(array $doc): array
    {
        if (in_array($doc['status'], ['authorized', 'cancelled'], true)) { return $this->refreshFiscalStatus($doc); }
        if (empty($doc['dps_id']) || (int) ($doc['attempts'] ?? 0) < 1) { throw new \RuntimeException('Este rascunho ainda não possui uma tentativa de transmissão para consultar.'); }
        $this->beginRecoveryCheck($doc, 'dps');
        try {
            $draft = \Nfse_nacional_model::decode($doc['draft_json']);
            $api = ($this->apiFactory)($doc['environment'], $this->certificate($draft['provider']['tax_id'], (string) $doc['environment']));
            $response = $api->findByDps($doc['dps_id']);
            if ($response) { return $this->authorize($doc, $this->resolveResponse($api, $response), 'dps'); }
        } catch (\Throwable $error) {
            $this->recordRecoveryFailure($doc, $error);
            if ($error instanceof ApiException) { throw $error; }
            throw new \RuntimeException('Não foi possível confirmar a transmissão. A DPS e a numeração originais foram preservadas; consulte novamente.', 0, $error);
        }
        $meta = FiscalStatus::metadata($this->model->document((int) $doc['id']));
        $meta['status_checked_at'] = \Nfse_nacional_model::now();
        $meta['status_check_error'] = '';
        $this->model->updateDocument((int) $doc['id'], ['status' => 'uncertain', 'last_error' => 'A consulta ainda não localizou uma NFS-e para esta DPS. Aguarde e consulte novamente; não foi realizada outra emissão.',
            'fiscal_meta_json' => \Nfse_nacional_model::json($meta)]);
        $this->model->event((int) $doc['id'], 'query_not_found', 'Consulta sem nota localizada para a DPS. Identificador preservado.');
        return $this->publicDocument($this->model->document((int) $doc['id']));
    }

    /** Recover an already submitted DPS by its official key. No reservation, signing or fiscal POST. */
    public function recoverByKey(int $id, string $key): array
    {
        $key = strtoupper(trim($key));
        if (!preg_match('/^[A-Z0-9]{50}$/D', $key)) { throw new \InvalidArgumentException('Informe a chave de acesso da NFS-e com 50 caracteres.'); }
        $alreadyOfficial = false;
        $result = $this->emissionLock($id, function () use ($id, $key, &$alreadyOfficial) {
            $doc = $this->model->document($id);
            if ($doc['status'] === 'cancelled') { throw new \RuntimeException('Esta NFS-e está cancelada. Consulte sua situação fiscal; a recuperação não pode reativá-la.'); }
            if ($doc['status'] === 'authorized') {
                if (!hash_equals((string) ($doc['access_key'] ?? ''), $key)) { throw new \InvalidArgumentException('A chave informada difere da NFS-e já vinculada a este documento.'); }
                $alreadyOfficial = true;
                return $this->refreshFiscalStatus($doc);
            }
            if (!in_array($doc['status'], ['uncertain', 'processing', 'rejected'], true)
                || empty($doc['dps_id']) || (int) ($doc['attempts'] ?? 0) < 1 || empty($doc['request_enc'])) {
                throw new \RuntimeException('A recuperação por chave exige a DPS original de uma tentativa de transmissão já realizada.');
            }
            $this->beginRecoveryCheck($doc, 'key');
            try {
                $original = $this->model->crypto()->decrypt($doc['request_enc']);
                $originalDocument = XmlSigner::loadSafe($original);
                $originalPaths = new \DOMXPath($originalDocument);
                $originalPaths->registerNamespace('n', XmlSigner::NFSE_NS);
                if (!XmlSigner::verify($original)
                    || $originalPaths->evaluate('string(/n:DPS/n:infDPS/@Id)') !== $doc['dps_id']
                    || $originalPaths->evaluate('string(/n:DPS/n:infDPS/n:tpAmb)') !== $doc['environment']) {
                    throw new \RuntimeException('A DPS original armazenada não passou na conferência.');
                }
                $draft = \Nfse_nacional_model::decode($doc['draft_json']);
                $api = ($this->apiFactory)($doc['environment'], $this->certificate($draft['provider']['tax_id'], (string) $doc['environment']));
                $response = $api->fetch($key);
                // Repeat the requested-key check at the service boundary, including injected adapters.
                if (!is_string($response['chaveAcesso'] ?? null) || !hash_equals($key, $response['chaveAcesso'])) {
                    throw new ApiException('O retorno não corresponde à chave de acesso informada. Nenhuma NFS-e foi vinculada.', 0, true);
                }
                return $this->authorize($doc, $response, 'key');
            } catch (\Throwable $error) {
                $this->recordRecoveryFailure($doc, $error);
                if ($error instanceof ApiException) { throw $error; }
                throw new \RuntimeException('Não foi possível recuperar a autorização pela chave informada. Confira a chave e consulte novamente; a DPS original foi preservada.', 0, $error);
            }
        });
        return $alreadyOfficial ? $result : $this->deliverIfRequested($id, $result);
    }

    private function beginRecoveryCheck(array $doc, string $source): void
    {
        $meta = FiscalStatus::metadata($doc);
        $meta['status_check_attempted_at'] = \Nfse_nacional_model::now();
        $this->model->updateDocument((int) $doc['id'], ['fiscal_meta_json' => \Nfse_nacional_model::json($meta)]);
        $this->model->event((int) $doc['id'], 'transmission_query_started', $source === 'key'
            ? 'Consulta da autorização pela chave de acesso iniciada. DPS, XML assinado e numeração originais preservados.'
            : 'Consulta da transmissão pela DPS iniciada. Nenhuma nova emissão ou numeração será gerada.');
    }

    private function recordRecoveryFailure(array $doc, \Throwable $error): void
    {
        $current = $this->model->document((int) $doc['id']);
        $meta = FiscalStatus::metadata($current);
        $message = $error instanceof ApiException && $error->getMessage() === DpsCorrelation::MISMATCH
            ? DpsCorrelation::MISMATCH
            : 'Não foi possível confirmar a autorização nesta consulta. A situação anterior, a DPS e a numeração foram preservadas.';
        $meta['status_check_error'] = $message;
        $this->model->updateDocument((int) $doc['id'], ['fiscal_meta_json' => \Nfse_nacional_model::json($meta), 'last_error' => $message]);
        $this->model->event((int) $doc['id'], 'transmission_query_failed', $message);
    }

    /** A GET-only refresh. Unavailable or ambiguous replies never erase prior fiscal evidence. */
    private function refreshFiscalStatus(array $doc): array
    {
        $id = (int) $doc['id'];
        $meta = FiscalStatus::metadata($doc);
        $meta['status_check_attempted_at'] = \Nfse_nacional_model::now();
        try {
            $status = $this->fiscalStatus($doc);
            if (!$status['has_official_xml']) { throw new \RuntimeException('O XML autorizado não está disponível para conferir a situação.'); }
            $draft = \Nfse_nacional_model::decode($doc['draft_json']);
            $api = ($this->apiFactory)($doc['environment'], $this->certificate($draft['provider']['tax_id'], (string) $doc['environment']));
            $events = $api->getEvents((string) $doc['access_key']);
            $cancellation = null;
            foreach ($events as $event) {
                // Repeat correlation at the persistence boundary, including custom API adapters.
                $registered = FiscalEvent::fromXml((string) ($event['xml'] ?? ''), (string) $doc['access_key'], (string) $doc['environment']);
                if ($registered['cancellation'] && ($cancellation === null
                    || strtotime($registered['processed_at']) > strtotime($cancellation['processed_at']))) {
                    $cancellation = $registered;
                }
            }
        } catch (\Throwable $error) {
            $meta['status_check_error'] = Config::text($error->getMessage(), 1000);
            $this->model->updateDocument($id, ['fiscal_meta_json' => \Nfse_nacional_model::json($meta)]);
            $this->model->event($id, 'fiscal_check_failed', 'A consulta de eventos não confirmou uma nova situação. O XML e a situação fiscal anterior foram preservados.');
            throw $error;
        }
        $meta['status_check_error'] = '';
        $meta['status_checked_at'] = \Nfse_nacional_model::now();
        $changes = [];
        if ($cancellation !== null) {
            $changed = $doc['status'] !== 'cancelled' || ($meta['cancellation_event_id'] ?? '') !== $cancellation['id'];
            $meta['cancellation_confirmed'] = true;
            $meta['cancelled_at'] = $cancellation['processed_at'];
            $meta['cancellation_event_id'] = $cancellation['id'];
            $meta['cancellation_record_number'] = $cancellation['record_number'];
            $changes = ['status' => 'cancelled', 'last_error' => null,
                'cancellation_event_enc' => $this->model->crypto()->encrypt($cancellation['xml'])];
            if ($changed) { $this->model->event($id, 'fiscal_cancelled', 'Cancelamento confirmado por evento registrado no Sistema Nacional NFS-e. XML original preservado.'); }
        } else {
            // No later empty result can undo a cancellation already proved by a registered event.
            $this->model->event($id, 'fiscal_checked', 'Eventos fiscais consultados. Nenhuma alteração confirmada na situação armazenada.');
        }
        $changes['fiscal_meta_json'] = \Nfse_nacional_model::json($meta);
        $this->model->updateDocument($id, $changes);
        return $this->publicDocument($this->model->document($id));
    }

    private function authorize(array $doc, array $response, ?string $confirmationSource = null): array
    {
        // submitPersisted writes request_enc after its caller obtained $doc.
        // Always compare against the durable request rather than that stale row.
        $doc = $this->model->document((int) $doc['id']);
        if ($doc['status'] === 'cancelled') { throw new \RuntimeException('Uma NFS-e cancelada não pode ser reativada pela recuperação da autorização.'); }
        $xml = ApiClient::decodeXml($response);
        $parsed = new NfseDocument($xml);
        $key = $parsed->accessKey();
        if (!hash_equals($doc['dps_id'], $parsed->dpsId()) || $parsed->environment() !== $doc['environment']
            || (!empty($response['chaveAcesso']) && !hash_equals((string) $response['chaveAcesso'], $key))
            || (!empty($response['idDps']) && !hash_equals((string) $response['idDps'], $parsed->dpsId()))
            || (isset($response['tipoAmbiente']) && (string) $response['tipoAmbiente'] !== $doc['environment'])) {
            throw new \RuntimeException('A resposta fiscal não corresponde à DPS, à chave ou ao ambiente desta emissão.');
        }
        if ($doc['status'] === 'authorized') {
            if (!hash_equals((string) ($doc['access_key'] ?? ''), $key)) { throw new \RuntimeException('Uma NFS-e diferente já está vinculada a este documento.'); }
            return $this->publicDocument($doc);
        }
        try {
            $expected = !empty($doc['request_enc'])
                ? $this->model->crypto()->decrypt($doc['request_enc'])
                : (new DpsBuilder())->build(\Nfse_nacional_model::decode($doc['draft_json']), (string) $doc['dps_series'], (string) $doc['dps_number']);
            DpsCorrelation::assertMatches($expected, $xml);
        } catch (\Throwable $error) {
            // Another application may have used the same issuer/series/number.
            // A matching identifier alone must never attach that invoice here.
            $this->model->updateDocument((int) $doc['id'], ['status' => in_array($doc['status'], ['processing', 'uncertain'], true) ? $doc['status'] : 'uncertain', 'last_error' => DpsCorrelation::MISMATCH]);
            $this->model->event((int) $doc['id'], 'dps_correlation_mismatch', DpsCorrelation::MISMATCH);
            throw new ApiException(DpsCorrelation::MISMATCH, 0, true, [], $error);
        }
        $meta = FiscalStatus::metadata($doc);
        $meta['processed_at'] = $parsed->text('dhProc');
        $meta['record_number'] = $parsed->text('nDFSe');
        $meta['authorization_received_at'] = \Nfse_nacional_model::now();
        if (in_array($confirmationSource, ['dps', 'key'], true)) {
            $meta['confirmation_source'] = $confirmationSource;
            $meta['status_check_attempted_at'] = $meta['status_check_attempted_at'] ?? \Nfse_nacional_model::now();
            $meta['status_checked_at'] = \Nfse_nacional_model::now();
            $meta['status_check_error'] = '';
        }
        $changes = ['status' => 'authorized', 'access_key' => $key, 'nfse_number' => $parsed->number(),
            'response_enc' => $this->model->crypto()->encrypt($xml), 'authorized_at' => \Nfse_nacional_model::now(), 'last_error' => null,
            'fiscal_meta_json' => \Nfse_nacional_model::json($meta)];
        $this->model->updateDocument((int) $doc['id'], $changes);
        // Queue bookkeeping must never turn an already confirmed fiscal success into a failure.
        try { if (method_exists($this->model, 'completeInvoiceAutomations')) { $this->model->completeInvoiceAutomations($doc); } }
        catch (\Throwable $ignored) { /* The next worker also rechecks the authorized document. */ }
        $this->model->event((int) $doc['id'], $confirmationSource !== null ? 'authorization_recovered' : 'authorized',
            $confirmationSource === 'dps' ? 'Autorização recuperada pela consulta da DPS. Chave e XML oficial vinculados à emissão original, sem nova transmissão.'
            : ($confirmationSource === 'key' ? 'Autorização recuperada pela chave de acesso. DPS e conteúdo fiscal conferidos; XML oficial vinculado sem nova transmissão.'
            : 'NFS-e autorizada e XML recebido.'));
        return $this->completeSubstitution($doc, $this->publicDocument($this->model->document((int) $doc['id'])));
    }

    public function xml(int $id): string
    {
        $doc = $this->model->document($id);
        if (!in_array($doc['status'], ['authorized', 'cancelled'], true) || empty($doc['response_enc'])) { throw new \RuntimeException('O XML ficará disponível após a autorização.'); }
        return $this->model->crypto()->decrypt($doc['response_enc']);
    }

    private function completeSubstitution(array $doc, array $result): array
    {
        $parentId = (int) ($doc['replacement_of_id'] ?? 0);
        if ($parentId < 1) { return $result; }
        try {
            $parent = $this->model->document($parentId);
            $meta = FiscalStatus::metadata($parent);
            $meta['replacement_authorized_id'] = (int) $doc['id'];
            $this->model->updateDocument($parentId, ['fiscal_meta_json' => \Nfse_nacional_model::json($meta)]);
            if ($parent['status'] !== 'cancelled') {
                $checked = $this->refreshFiscalStatus($this->model->document($parentId));
                if ($checked['status'] !== 'cancelled') { throw new \RuntimeException('O evento de cancelamento da nota original ainda não foi localizado.'); }
            }
        } catch (\Throwable $error) {
            // The new XML already proves authorization. A failed source-event lookup
            // must never roll it back to uncertain or cause another replacement POST.
            $result['substitution_warning'] = 'NFS-e substituta autorizada. Consulte a situação da nota original para recuperar seu evento de cancelamento.';
        }
        return $result;
    }

    /** Produces the saved snapshot only: no validation, certificate, DPS reservation, network or write. */
    public function pdf(int $id): string
    {
        $doc = $this->model->document($id);
        $status = $this->fiscalStatus($doc);
        if (in_array($doc['status'], ['authorized', 'cancelled'], true)) {
            if (!$status['has_official_xml'] || ($doc['status'] === 'cancelled' && !$status['is_cancelled'])) {
                throw new \RuntimeException('O documento não possui evidência fiscal suficiente para gerar este DANFSe. Consulte a situação da nota.');
            }
            return Danfe::render($this->model->crypto()->decrypt($doc['response_enc']), $status);
        }
        return Danfe::preview(\Nfse_nacional_model::decode($doc['draft_json']), $doc, $status);
    }

    public function fiscalStatus(array $doc): array
    {
        $parsed = null;
        if (in_array($doc['status'] ?? '', ['authorized', 'cancelled'], true) && !empty($doc['response_enc'])) {
            $parsed = new NfseDocument($this->model->crypto()->decrypt($doc['response_enc']));
            if ($parsed->environment() !== (string) $doc['environment']
                || !hash_equals($parsed->accessKey(), (string) ($doc['access_key'] ?? ''))
                || !hash_equals($parsed->dpsId(), (string) ($doc['dps_id'] ?? ''))) {
                throw new \RuntimeException('O XML armazenado não corresponde ao documento fiscal.');
            }
        }
        if (($doc['status'] ?? '') === 'cancelled') {
            $meta = FiscalStatus::metadata($doc);
            if ($parsed === null || empty($doc['cancellation_event_enc']) || ($meta['cancellation_confirmed'] ?? false) !== true) {
                throw new \RuntimeException('Não há evento fiscal confirmado para apresentar o documento como cancelado.');
            }
            $event = FiscalEvent::fromXml($this->model->crypto()->decrypt($doc['cancellation_event_enc']), $parsed->accessKey(), $parsed->environment());
            if (!$event['cancellation'] || !hash_equals($event['id'], (string) ($meta['cancellation_event_id'] ?? ''))
                || !hash_equals($event['processed_at'], (string) ($meta['cancelled_at'] ?? ''))) {
                throw new \RuntimeException('O evento armazenado não confirma o cancelamento deste documento.');
            }
            $meta['cancellation_record_number'] = $event['record_number'];
            $doc['fiscal_meta_json'] = \Nfse_nacional_model::json($meta);
        }
        return FiscalStatus::describe($doc, $parsed);
    }

    public function send(int $id): array
    {
        if (!nfse_nacional_can('send')) { throw new \RuntimeException('Você não tem permissão para enviar documentos.'); }
        return $this->model->lock('document:' . $id, function () use ($id) {
            $doc = $this->model->document($id);
            if ($doc['status'] !== 'authorized') { throw new \RuntimeException('Somente notas autorizadas podem ser enviadas.'); }
            if (array_key_exists('revision', $doc)) {
                $replacement = $this->model->replacementFor($id);
                if ($replacement && (!empty($replacement['dps_id']) || (int) $replacement['attempts'] > 0)) {
                    throw new \RuntimeException('Esta nota possui substituição transmitida ou em consulta. Abra a substituta e confira a situação antes de enviar os documentos.');
                }
            }
            if ($doc['email_status'] === 'sent') { return $this->publicDocument($doc); }
            if (in_array($doc['email_status'], ['sending', 'uncertain'], true)) { throw new \RuntimeException('Um envio anterior está sem confirmação. Verifique o servidor de e-mail antes de reenviar para evitar duplicidade.'); }
            $email = (string) $doc['recipient_email'];
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { throw new \RuntimeException('O tomador da emissão não possui e-mail válido. Baixe os documentos para encaminhamento.'); }
            $xml = $this->xml($id);
            $pdf = Danfe::render($xml, $this->fiscalStatus($doc));
            Mailer::assertReady();
            $this->model->updateDocument($id, ['email_status' => 'sending']);
            try { Mailer::send($email, $xml, $pdf); }
            catch (\Throwable $e) {
                $this->model->updateDocument($id, ['email_status' => 'uncertain']);
                $this->model->event($id, 'email_uncertain', 'O servidor de e-mail não confirmou a entrega da mensagem para envio.');
                throw $e;
            }
            $this->model->updateDocument($id, ['email_status' => 'sent', 'email_sent_at' => \Nfse_nacional_model::now()]);
            $this->model->event($id, 'email_sent', 'O servidor SMTP aceitou a mensagem com XML e DANFSe. A aceitação não confirma leitura ou entrega na caixa final.');
            return $this->publicDocument($this->model->document($id));
        });
    }

    public function publicDocument(array $doc): array
    {
        $doc['fiscal_status'] = $this->fiscalStatus($doc);
        // The optional visual summary must never turn an already confirmed
        // authorization into an uncertain transmission because of legacy display data.
        $doc['tax_summary'] = ['ibpt' => null, 'ibpt_error' => '', 'iss' => null, 'inputs' => [], 'summary_error' => ''];
        try {
            $draft = \Nfse_nacional_model::decode($doc['draft_json'] ?? null);
            $doc['tax_summary'] = DraftTax::summary($draft) + ['summary_error' => ''];
        } catch (\Throwable $error) {
            $doc['tax_summary']['summary_error'] = 'Não foi possível apresentar o resumo dos tributos deste documento. Confira os dados e o XML fiscal disponível.';
        }
        if (in_array($doc['status'], ['authorized', 'cancelled'], true) && !empty($doc['response_enc'])) {
            $doc['tax_summary']['iss'] = null;
            try {
                $official = new NfseDocument($this->model->crypto()->decrypt($doc['response_enc']));
                $doc['tax_summary']['iss'] = [
                    'base' => $official->text('valores/vBC'), 'rate' => $official->text('valores/pAliqAplic'),
                    'amount' => $official->text('valores/vISSQN'), 'retention' => $official->dpsText('valores/trib/tribMun/tpRetISSQN'),
                    'retained' => in_array($official->dpsText('valores/trib/tribMun/tpRetISSQN'), ['2', '3'], true), 'official' => true,
                ];
            } catch (\Throwable $error) {
                $doc['tax_summary']['summary_error'] = 'Não foi possível apresentar o resumo do ISSQN. Consulte o XML fiscal para conferir a apuração.';
            }
        }
        unset($doc['request_enc'], $doc['response_enc'], $doc['draft_json'], $doc['cancellation_event_enc'], $doc['fiscal_meta_json']);
        return $doc;
    }

    public function retryEmail(int $id): array
    {
        if (!nfse_nacional_can('send')) { throw new \RuntimeException('Você não tem permissão para enviar documentos.'); }
        $this->model->lock('document:' . $id, function () use ($id) {
            $doc = $this->model->document($id);
            if ($doc['status'] !== 'authorized') { throw new \RuntimeException('Somente uma NFS-e autorizada pode ser enviada.'); }
            if (in_array($doc['email_status'], ['sending', 'uncertain'], true)) {
                Mailer::assertReady();
                $this->model->updateDocument($id, ['email_status' => 'pending']);
                $this->model->event($id, 'email_retry_confirmed', 'Operador solicitou novo envio após conferência do resultado anterior; mesmos documentos e destinatário.');
            }
        });
        return $this->send($id);
    }
}
