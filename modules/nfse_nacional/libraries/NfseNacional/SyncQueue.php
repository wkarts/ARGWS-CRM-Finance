<?php
declare(strict_types=1);
namespace NfseNacional;

/** One durable execution per issuer/environment; confirmed ADN cursor remains authoritative. */
final class SyncQueue
{
    public function __construct(private $model, private $service = null)
    {
        $this->service ??= new NationalService($model);
    }
    private function table(): string { return db_prefix() . 'nfse_nacional_sync_runs'; }
    public static function schema(): array
    {
        return ['sync_runs' => "issuer_tax_id VARCHAR(14) NOT NULL, environment CHAR(1) NOT NULL, scope CHAR(64) NOT NULL,
            mode VARCHAR(12) NOT NULL, date_from DATE NULL, date_to DATE NULL, staff_id INT NOT NULL,
            baseline_processed BIGINT NOT NULL DEFAULT 0, baseline_batches BIGINT NOT NULL DEFAULT 0,
            status VARCHAR(20) NOT NULL, batches INT NOT NULL DEFAULT 0, processed BIGINT NOT NULL DEFAULT 0,
            imported BIGINT NOT NULL DEFAULT 0, events BIGINT NOT NULL DEFAULT 0, failures INT NOT NULL DEFAULT 0,
            next_run_at DATETIME NOT NULL, last_error TEXT NULL, started_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, last_worker_at DATETIME NULL,
            PRIMARY KEY(issuer_tax_id,environment), KEY queue(status,next_run_at)"];
    }
    public static function period(string $mode, string $from, string $to): array
    {
        if (!in_array($mode, ['full', 'period'], true)) { throw new \InvalidArgumentException('Selecione histórico completo ou período.'); }
        if ($mode === 'full') { return [null, null]; }
        foreach ([$from, $to] as $value) {
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
            if (!$date || $date->format('Y-m-d') !== $value) { throw new \InvalidArgumentException('Informe as datas inicial e final do período.'); }
        }
        if ($from > $to) { throw new \InvalidArgumentException('A data inicial deve ser anterior ou igual à final.'); }
        return [$from, $to];
    }
    public function state(string $environment): array
    {
        $issuer = (new NationalRepository($this->model))->contextTaxId();
        $query = $this->model->db->where('issuer_tax_id', $issuer)->where('environment', NationalRepository::environment($environment))
            ->where('scope', nfse_nacional_scope())->get($this->table());
        if (!$query) { throw new \RuntimeException('Atualize a instalação para utilizar a sincronização em segundo plano.'); }
        $run = $query->row_array() ?: [];
        if ($run) {
            $cursor = (new NationalRepository($this->model))->state($environment);
            $run['processed'] = max(0, (int) $cursor['processed_count'] - (int) $run['baseline_processed']);
            $run['batches'] = max(0, (int) $cursor['batch_count'] - (int) $run['baseline_batches']);
            $run['next_attempt_at'] = max((string) $run['next_run_at'], (string) ($cursor['next_sync_at'] ?? ''));
            $seen = $run['last_worker_at'] ?? null;
            $run['worker_stalled'] = in_array($run['status'], ['pending', 'running', 'waiting'], true)
                && strtotime($run['next_attempt_at'] . ' UTC') < time() - 180
                && (!$seen || strtotime($seen . ' UTC') < time() - 180);
        }
        return $run;
    }
    public function start(string $environment, string $mode, string $from = '', string $to = ''): array
    {
        if (!nfse_nacional_can('national_sync') || !nfse_nacional_can('national_view')) { throw new \RuntimeException('Sem permissão para sincronizar.'); }
        if ($environment !== $this->model->settings()['environment']) { throw new \RuntimeException('Selecione este ambiente nas configurações antes de sincronizar.'); }
        [$from, $to] = self::period($mode, $from, $to);
        $issuer = (new NationalRepository($this->model))->contextTaxId();
        return $this->model->lock('national-run:' . $issuer . ':' . $environment, function () use ($environment, $issuer, $mode, $from, $to) {
            $old = $this->state($environment); $now = gmdate('Y-m-d H:i:s');
            if ($old && in_array($old['status'], ['pending', 'running', 'waiting'], true)) {
                // Full history and period use the same confirmed ADN stream.
                // Update only the requested selection under the run lock; never reset its cursor or counters.
                $cursor = (new NationalRepository($this->model))->state($environment);
                $due = $cursor['next_sync_at'] && $cursor['next_sync_at'] > $now ? $cursor['next_sync_at'] : $now;
                $this->write($old, ['mode' => $mode, 'date_from' => $from, 'date_to' => $to,
                    'staff_id' => (int) get_staff_user_id(), 'status' => $due > $now ? 'waiting' : 'pending',
                    'next_run_at' => $due, 'last_error' => null, 'failures' => 0]);
                return $this->state($environment);
            }
            $row = ['issuer_tax_id' => $issuer, 'environment' => $environment, 'scope' => nfse_nacional_scope(),
                'mode' => $mode, 'date_from' => $from, 'date_to' => $to, 'staff_id' => (int) get_staff_user_id(),
                'status' => 'pending', 'failures' => 0, 'next_run_at' => $now, 'last_error' => null, 'updated_at' => $now];
            // A failed/interrupted run resumes the confirmed cursor and retains its counters.
            if ($old && $old['mode'] === $mode && $old['date_from'] === $from && $old['date_to'] === $to && $old['status'] !== 'completed') {
                $this->write($old, $row);
            } else {
                $cursorState = (new NationalRepository($this->model))->state($environment);
                $row += ['baseline_processed' => (int) $cursorState['processed_count'], 'baseline_batches' => (int) $cursorState['batch_count'], 'batches' => 0, 'processed' => 0, 'imported' => 0, 'events' => 0, 'failures' => 0, 'started_at' => $now, 'last_worker_at' => null];
                if ($old) { $this->write($old, $row); }
                elseif ($this->model->db->insert($this->table(), $row) === false) { throw new \RuntimeException('Não foi possível agendar a sincronização.'); }
            }
            return $this->state($environment);
        });
    }
    public function run(int $maxBatches = 3, bool $interactive = false): void
    {
        if ($interactive && (!nfse_nacional_can('national_sync') || !nfse_nacional_can('national_view'))) { throw new \RuntimeException('Sem permissão para sincronizar.', 403); }
        $settings = $this->model->settings();
        $query = $this->model->db->where('scope', nfse_nacional_scope())->where('issuer_tax_id', $settings['provider']['tax_id'])
            ->where('environment', $settings['environment'])->where_in('status', ['pending', 'running', 'waiting'])
            ->where('next_run_at <=', gmdate('Y-m-d H:i:s'))->limit(1)->get($this->table());
        if (!$query) { throw new \RuntimeException('Não foi possível ler a fila de sincronização.'); }
        foreach ($query->result_array() as $run) {
            $this->model->lock('national-run:' . $run['issuer_tax_id'] . ':' . $run['environment'], function () use ($run, $maxBatches, $interactive) {
                // Re-read under the lock: another worker may have completed or postponed the same run.
                $fresh = $this->model->db->where('issuer_tax_id', $run['issuer_tax_id'])->where('environment', $run['environment'])
                    ->where('scope', nfse_nacional_scope())->get($this->table());
                if (!$fresh) { throw new \RuntimeException('Não foi possível revalidar a fila de sincronização.'); }
                $run = $fresh->row_array() ?: [];
                if (!in_array($run['status'] ?? '', ['pending', 'running', 'waiting'], true)
                    || $run['next_run_at'] > gmdate('Y-m-d H:i:s')) { return; }
                $currentSettings = $this->model->settings();
                if ($currentSettings['environment'] !== $run['environment'] || $currentSettings['provider']['tax_id'] !== $run['issuer_tax_id']) { return; }
                if ($interactive && (int) $run['staff_id'] !== (int) get_staff_user_id()) { throw new \RuntimeException('Retome a sincronização com o usuário atual antes de processar.', 403); }
                $run['last_worker_at'] = gmdate('Y-m-d H:i:s');
                $this->write($run, ['last_worker_at' => $run['last_worker_at']]);
                $actor = $this->model->db->where('staffid', (int) $run['staff_id'])->where('active', 1)->get(db_prefix() . 'staff')->row_array();
                if (!$actor) { $this->write($run, ['status' => 'failed', 'last_error' => 'O funcionário que iniciou a sincronização não está ativo. Inicie novamente com um usuário autorizado.']); return; }
                if (empty($actor['admin'])) {
                    $grants = $this->model->db->where('staff_id', (int) $run['staff_id'])->where('feature', 'nfse_nacional')->get(db_prefix() . 'staffpermissions')->result_array();
                    if (array_diff(['view', 'national_sync'], array_column($grants, 'capability'))) {
                        $this->write($run, ['status' => 'failed', 'last_error' => 'Permissão de sincronização revogada.']); return;
                    }
                }
                $context = ['kind' => 'national_sync', 'scope' => nfse_nacional_scope(), 'invoice_id' => 0, 'client_id' => 0,
                    'staff_id' => (int) $run['staff_id'], 'company_id' => (defined('PME_CONTEXT_READY') && PME_CONTEXT_READY === true) ? (string) $GLOBALS['pme_context']->companyId : 'standalone',
                    'organization_id' => (defined('PME_CONTEXT_READY') && PME_CONTEXT_READY === true) ? (string) ($GLOBALS['pme_context']->organizationId ?? '') : ''];
                $process = function () use ($run, $maxBatches) {
                    $run = $this->state($run['environment']);
                    if (!in_array($run['status'], ['pending', 'running', 'waiting'], true)) { return; }
                    $started = microtime(true);
                    for ($i = 0; $i < max(1, min(10, $maxBatches)); ++$i) {
                        try {
                            $before = (new NationalRepository($this->model))->state($run['environment']);
                            $this->write($run, ['status' => 'running']);
                            $result = $this->service->sync($run['environment']);
                            // Counts are committed atomically with the ADN cursor, surviving a killed worker.
                            $run['batches'] = max(0, (int) $result['state']['batch_count'] - (int) $run['baseline_batches']);
                            $run['processed'] = max(0, (int) $result['state']['processed_count'] - (int) $run['baseline_processed']);
                            $run['imported'] += (int) $result['imported']; $run['events'] += (int) $result['events'];
                            $run['last_error'] = null; $run['failures'] = 0;
                            if (!empty($result['throttled'])) {
                                $run['status'] = 'waiting';
                                $run['next_run_at'] = $result['next_sync_at'] ?: gmdate('Y-m-d H:i:s', time() + 3600);
                            } elseif ($result['has_more']) {
                                if (DistributionClient::compareNsu($result['state']['last_nsu'], $before['last_nsu']) <= 0) { throw new \RuntimeException('Cursor sem avanço.'); }
                                $run['status'] = 'pending'; $run['next_run_at'] = gmdate('Y-m-d H:i:s');
                            } else { $run['status'] = 'completed'; }
                            $this->write($run, $run);
                            if ($run['status'] !== 'pending' || microtime(true) - $started > 20) { break; }
                            if ($i + 1 < max(1, min(10, $maxBatches))) { usleep(1000000); }
                        } catch (\Throwable $error) {
                            $failures = (int) $run['failures'] + 1;
                            $message = $error instanceof ApiException
                                ? 'Falha na consulta ADN (HTTP ' . $error->httpStatus() . '). Consulte os logs. Cursor e arquivos preservados.'
                                : ($error instanceof SyncFailure ? $error->getMessage() : 'A sincronização não foi concluída. Verifique o certificado disponível e os logs. O cursor confirmado foi preservado.');
                            $this->write($run, ['status' => $failures < 5 ? 'waiting' : 'failed', 'failures' => $failures,
                                'next_run_at' => gmdate('Y-m-d H:i:s', time() + min(3600, 60 * (2 ** min($failures, 6)))), 'last_error' => $message]);
                            try { OperationalLog::current()->record('error', 'national.sync', $message, ['environment' => $run['environment'], 'stage' => $error instanceof SyncFailure ? $error->stage : 'background', 'exception_class' => get_class($error), 'http_status' => $error instanceof ApiException ? $error->httpStatus() : 0, 'operation_status' => 'failed']); } catch (\Throwable $ignored) {}
                            break;
                        }
                    }
                };
                // An explicit request keeps its authenticated company-certificate provider.
                // The cron path retains its existing server-side delegation and scoped access.
                if ($interactive) { $process(); } else { AutomationContext::run($context, $process); }
            });
        }
    }
    private function write(array $run, array $changes): void
    {
        unset($changes['next_attempt_at'], $changes['worker_stalled']);
        $changes['updated_at'] = gmdate('Y-m-d H:i:s');
        if ($this->model->db->where('issuer_tax_id', $run['issuer_tax_id'])->where('environment', $run['environment'])->where('scope', nfse_nacional_scope())
            ->update($this->table(), $changes) === false) { throw new \RuntimeException('Não foi possível salvar o progresso. O cursor confirmado permanece preservado.'); }
    }
}
