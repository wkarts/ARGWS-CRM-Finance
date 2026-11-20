<?php

declare(strict_types=1);

namespace Multiempresa\Http;

use Multiempresa\Application\{App,Catalog,Provisioner,Events,Updates};
use Multiempresa\Domain\{Failure,Id};
use Multiempresa\Infrastructure\AdvisoryLock;
use Multiempresa\Perfex\Adapter;

final class Panel
{
    public function __construct(private App $app, private array $actor) {}
    public function action(string $action): array
    {
        $a = $this->actor;
        $p = $_POST;
        if ($action === 'organization') {
            $this->app->catalog->createOrganization($a, $p['name'] ?? '', $p['slug'] ?? '');
            return ['Organização cadastrada.',null];
        }
        if ($action === 'organization_update') {
            $org=(string)($p['organization_id'] ?? '');(new \Multiempresa\Application\Organizations($this->app))->save($a,['id'=>$org,'name'=>$p['name']??'','active'=>(int)!empty($p['active'])]);
            return ['Organização atualizada.',null];
        }
        if ($action === 'company_rename') {
            $c=$this->managedCompany(); $this->app->db->exec('UPDATE pme_companies SET name=? WHERE id=?',[Catalog::name((string)($p['name'] ?? '')),$c['id']]);
            $this->app->audit->log('company.renamed',$a['id'],$c['id']); return ['Nome de exibição atualizado.',null];
        }
        if ($action === 'org_admin_revoke') {
            $this->platform(); $org=(string)($p['organization_id'] ?? ''); $u=$this->userByEmail($p['email'] ?? '');
            $this->app->db->exec('DELETE FROM pme_org_members WHERE organization_id=? AND user_id=?',[$org,$u['id']]);
            $this->app->audit->log('organization.admin_revoked',$a['id'],null,['organization_id'=>$org,'user_id'=>$u['id']]);
            return ['Administração da organização revogada. Os vínculos operacionais permanecem individuais.',null];
        }
        if ($action === 'org_admin') {
            $org = (string)($p['organization_id'] ?? '');
            $this->app->catalog->assertManager($a, $org);
            $u = $this->userByEmail($p['email'] ?? '');
            $this->app->catalog->assertUserOrganization($u['id'],$org,true);
            $this->app->db->transaction(function () use ($org, $u) {
                $this->app->db->exec('DELETE FROM pme_org_members WHERE organization_id=? AND user_id=?', [$org,$u['id']]);
                $this->app->db->exec("INSERT INTO pme_org_members(organization_id,user_id,role) VALUES(?,?,'admin')", [$org,$u['id']]);
            });
            $this->app->audit->log('organization.admin', $a['id'], null, ['organization_id' => $org,'user_id' => $u['id']]);
            return ['Administrador da organização autorizado.',null];
        }
        if ($action === 'company') {
            $p['request_key']=$p['request_key']??bin2hex(random_bytes(24));
            $p['language']=$p['language']??'portuguese_br';$p['timezone']=$p['timezone']??'America/Bahia';
            $workflow=(new \Multiempresa\Application\Onboarding($this->app))->begin($a,$p);
            return ['Cadastro criado. Abra Andamento na grid para concluir todas as etapas. Execução: '.$workflow['id'],null];
        }
        if ($action === 'grant') {
            $u = $this->userByEmail($p['email'] ?? '');
            $this->app->catalog->grant($a, $p['company_id'] ?? '', $u['id'], (int)($p['staff_id'] ?? 0));
            return ['Acesso vinculado às permissões do funcionário local.',null];
        }
        if ($action === 'revoke') {
            $this->app->catalog->revoke($a, $p['company_id'] ?? '', $p['user_id'] ?? '');
            return ['Acesso revogado.',null];
        }
        if ($action === 'company_status') {
            $c = $this->managedCompany();
            $workflow=$this->app->db->one('SELECT status FROM pme_onboardings WHERE company_id=?',[$c['id']]);if($workflow&&$workflow['status']!=='done'){throw new Failure('onboarding_pending','Conclua todas as etapas do assistente antes de liberar a empresa.');}
            if (str_starts_with((string) ($c['error_code'] ?? ''), 'module_')) {
                throw new Failure('module_recovery', 'Restaure o backup anterior à instalação do módulo antes de liberar a empresa.');
            }
            $state = $p['status'] ?? '';
            if (!in_array($state, ['active','suspended'], true) || !in_array($c['status'], ['active','suspended','maintenance'], true)) {
                throw new Failure('state', 'Transição de situação não permitida.');
            }
            $lock = new AdvisoryLock($this->app, 'updates');
            $gate = $this->app->db->one('SELECT * FROM pme_gate WHERE id=1');
            if ($gate['state'] !== 'active') {
                throw new Failure('maintenance', 'A versão global está em manutenção.');
            }
            if ($state === 'active') {
                $r = $this->app->db->one('SELECT root FROM pme_releases WHERE id=?', [$gate['release_id']]);
                (new Adapter($this->app))->verify($c, $r['root']);
            }
            $this->app->db->exec('UPDATE pme_companies SET status=? WHERE id=?', [$state,$c['id']]);
            $this->app->audit->log('company.status', $a['id'], $c['id'], ['status' => $state]);
            return ['Situação atualizada.',null];
        }
        if ($action === 'token') {
            $raw = $this->app->auth->issueToken($a, $p['company_id'] ?? '', $p['name'] ?? '', (array)($p['scopes'] ?? []), (int)($p['days'] ?? 30));
            return ['Token criado. Copie agora: o segredo não será mostrado novamente.',$raw];
        }
        if ($action === 'revoke_token') {
            $this->app->db->exec('UPDATE pme_tokens SET revoked=1 WHERE id=? AND user_id=?', [$p['id'] ?? '',$a['id']]);
            return ['Token revogado.',null];
        }
        if ($action === 'endpoint') {
            $c = $this->managedCompany();
            $v = (new Events($this->app))->endpoint($a, $c['id'], $p['provider'] ?? 'hmac', $p['processor'] ?? 'durable_inbox');
            return ['Integração criada. Configure o endereço e o segredo no provedor.',json_encode($v, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)];
        }
        if ($action === 'endpoint_active') {
            $c = $this->managedCompany();
            $this->app->db->exec('UPDATE pme_endpoints SET active=? WHERE id=? AND company_id=?', [(int)($p['active'] ?? 0),$p['id'] ?? '',$c['id']]);
            return ['Integração atualizada.',null];
        }
        if ($action === 'event_retry') {
            (new Events($this->app))->retry($a, $p['id'] ?? '');
            return ['Evento devolvido à fila.',null];
        }
        if ($action === 'backup') {
            $c = $this->managedCompany();
            $this->enqueue('backup', $c['id']);
            return ['Backup agendado para o próximo ciclo do worker.',null];
        }
        if ($action === 'plan_update') {
            $this->platform();
            $id = (new Updates($this->app, new Adapter($this->app)))->plan($a, $p['release_id'] ?? '');
            return ['Plano criado: '.$id.'. Revise a versão e execute quando estiver pronto.',null];
        }
        if ($action === 'execute_update') {
            $this->platform();
            $this->enqueue('update', $p['id'] ?? '');
            return ['Atualização enfileirada. Acompanhe as etapas nesta central.',null];
        }
        if ($action === 'module_approve') {
            $c=$this->managedCompany();
            (new \Multiempresa\Application\Modules($this->app))->approve($c['id'],(string)($p['module'] ?? ''),!empty($p['reviewed']),($p['release_id'] ?? '') ?: null);
            return ['Módulo aprovado; backup anterior preservado.',null];
        }
        if ($action === 'module_disable') {
            $c=$this->managedCompany();
            (new \Multiempresa\Application\Modules($this->app))->disable($c['id'],(string)($p['module'] ?? ''));
            return ['Módulo desativado nesta empresa.',null];
        }
        if ($action === 'register_release') {
            $this->platform(); $id=(new Adapter($this->app))->register((string)($p['root'] ?? ''));
            return ['Versão registrada: '.$id,null];
        }
        if ($action === 'run_jobs') {
            $this->platform(); (new \Multiempresa\Application\Scheduler($this->app))->tick();
            return ['Ciclo executado. Consulte os estados das rotinas.',null];
        }
        if ($action === 'restore') {
            $c=$this->managedCompany();
            $updateId=trim((string)($p['update_id'] ?? '')) ?: null;
            if ($updateId) {
                $this->platform();
                $step=$this->app->db->one('SELECT backup FROM pme_update_steps WHERE update_id=? AND company_id=?',[$updateId,$c['id']]);
                $backup=$step['backup'] ?? '';
                if (!$backup) { throw new Failure('backup','Backup da etapa não encontrado.'); }
            } else {
                $job=$this->app->db->one("SELECT * FROM pme_jobs WHERE id=? AND type='backup' AND status='done' AND target=?",[$p['job_id'] ?? '',$c['id']]);
                if (!$job) { throw new Failure('backup','Backup concluído não encontrado para esta empresa.'); }
                $backup=json_decode($job['result'],true,64,JSON_THROW_ON_ERROR)['backup'];
            }
            $connection=['host'=>$p['host'] ?? '', 'port'=>(int)($p['port'] ?? 3306),'database'=>$p['database'] ?? '','username'=>$p['username'] ?? '','password'=>$p['db_password'] ?? ''];
            (new \Multiempresa\Application\Recovery($this->app))->restore($c['id'],$backup,$connection,(string)($p['prefix'] ?? ''),$updateId);
            return ['Backup restaurado em destino novo. Empresa suspensa para conferência.',null];
        }
        throw new Failure('action', 'Ação desconhecida.');
    }
    private function enqueue(string $type, string $target): void
    {
        $this->app->db->exec("INSERT INTO pme_jobs(id,type,target,actor_id,status,created_at) VALUES(?,?,?,?,'pending',?)", [Id::new(),$type,$target,$this->actor['id'],time()]);
    }
    private function managedCompany(): array
    {
        $c = $this->app->catalog->company($_POST['company_id'] ?? '');
        $this->app->catalog->assertManager($this->actor, $c['organization_id']);
        if($c['status']==='removed'){throw new Failure('company_removed','Esta empresa foi removida.',409);}
        return $c;
    }
    private function platform(): void
    {
        if (!(int)$this->actor['platform_admin']) {
            throw new Failure('platform_admin', 'Esta ação exige administrador global.', 403);
        }
    }
    private function userByEmail(string $email): array
    {
        $table=Id::identifier($this->app->config['native_prefix'].'staff');
        $q=$this->app->db->pdo->prepare('SELECT staffid FROM `'.$table.'` WHERE email=? AND active=1'); $q->execute([Id::email($email)]);
        $id=$q->fetchColumn();
        return $id ? $this->app->auth->syncNative((int)$id) : throw new Failure('user','Cadastre o funcionário ativo na instalação principal.');
    }
    public function render(string $notice, ?string $secret): void
    {
        $a = $this->actor;
        $db = $this->app->db;
        $tab = $_GET['tab'] ?? 'companies';
        $admin = (bool)$a['platform_admin'];
        if (in_array($tab,['companies','organizations','settings','logs','modules'],true)) {
            $app=$this->app;$actor=$this->actor;
            require dirname(__DIR__,2).'/views/companies.php';
            return;
        }
        $orgs = $admin ? $db->all('SELECT * FROM pme_organizations ORDER BY name') : $db->all("SELECT o.* FROM pme_organizations o JOIN pme_org_members m ON m.organization_id=o.id WHERE m.user_id=? AND m.role='admin' ORDER BY o.name", [$a['id']]);
        $companies = $admin ? $db->all("SELECT c.*,o.slug AS organization_slug,o.name AS organization_name FROM pme_companies c JOIN pme_organizations o ON o.id=c.organization_id WHERE c.status<>'removed' ORDER BY o.name,c.name") : $db->all("SELECT c.*,o.slug AS organization_slug,o.name AS organization_name FROM pme_companies c JOIN pme_organizations o ON o.id=c.organization_id JOIN pme_org_members m ON m.organization_id=o.id WHERE m.user_id=? AND m.role='admin' AND c.status<>'removed' ORDER BY c.name", [$a['id']]);
        if(!$admin){$assigned=$this->app->catalog->organizationFor($a['id']);$orgs=array_values(array_filter($orgs,static fn($o)=>$o['id']===$assigned));$companies=array_values(array_filter($companies,static fn($c)=>$c['organization_id']===$assigned));}
        $accessible = $this->app->catalog->accessible($a['id']);
        echo '<div class="multiempresa-panel pme-legacy">';
        require dirname(__DIR__,2).'/views/navigation.php';
        if ($notice) {
            echo '<div class="alert alert-info" role="status">'.self::e($notice).'</div>';
        }
        if ($secret !== null) {
            echo '<pre class="secret">'.self::e($secret).'</pre>';
        }
        if ($tab === 'access') {
            echo '<h1>Usuários e acessos</h1><p class="text-muted">A identidade é global. Cargos e permissões operacionais pertencem ao funcionário no CRM de cada empresa.</p>';
            echo '<p>Cadastre usuários, senha e autenticação em duas etapas em <a href="'.self::e(admin_url('staff')).'">Funcionários do Perfex</a>. O vínculo abaixo usa essa identidade nativa.</p>';
            if ($companies) {
                echo '<details open><summary>Vincular funcionário a uma empresa</summary>';
                $this->form('grant');
                $this->select('company_id', 'Empresa', $companies);
                echo '<label class="form-group">Pesquisar colaborador<input class="form-control" type="search" data-staff-search placeholder="Nome ou e-mail" autocomplete="off"></label><label class="form-group">Colaborador desta empresa<select class="form-control" name="staff_id" required disabled><option value="">Selecione uma empresa</option></select></label><input type="hidden" name="email"><p class="text-muted" data-staff-message role="status">A identidade central deve ter o mesmo e-mail do colaborador selecionado.</p>';
                echo '<button class="btn btn-primary">Autorizar acesso</button></form></details>';
            }
            if ($orgs) {
                echo '<details><summary>Autorizar administrador de organização</summary>';
                $this->form('org_admin');
                $this->select('organization_id', 'Organização', $orgs);
                $this->input('email', 'E-mail', 'email');
                echo '<button class="btn btn-primary">Autorizar administração</button></form></details>';
                if ($admin) {
                    $this->form('org_admin_revoke'); $this->select('organization_id','Organização',$orgs); $this->input('email','E-mail do administrador a revogar','email'); echo '<button class="btn btn-danger">Revogar administração da organização</button></form>';
                }

            }
            $ids = array_column($companies, 'id');
            foreach ($companies as $c) {
                $members = $db->all('SELECT m.*,u.name,u.email FROM pme_memberships m JOIN pme_users u ON u.id=m.user_id WHERE m.company_id=?', [$c['id']]);
                if (!$members) {
                    continue;
                }
                echo '<h2>'.self::e(($c['organization_name']??'').' / '.$c['name']).'</h2><div class="table-responsive"><table class="table table-striped table-hover"><tr><th>Identidade</th><th>Funcionário</th><th>Situação</th><th></th></tr>';
                foreach ($members as $m) {
                    echo '<tr><td>'.self::e($m['name']).'<small>'.self::e($m['email']).'</small></td><td><details><summary>Vínculo local</summary>ID '.(int)$m['staff_id'].'</details></td><td>'.((int)$m['active'] ? 'Autorizado' : 'Revogado').'</td><td>';
                    $this->form('revoke', ['company_id' => $c['id'],'user_id' => $m['user_id']]);
                    echo '<button class="btn btn-danger" '.(!(int)$m['active']?'disabled':'').'>Revogar</button></form></td></tr>';
                }
                echo '</table></div>';
            }
        } elseif ($tab === 'integrations') {
            echo '<h1>Integrações</h1><p class="text-muted">Recurso opcional para conectar outros sistemas. Não é necessário para cadastrar empresas, provisionar ambientes ou consultar CNPJ e CEP.</p><div class="grid pme-integrations-grid"><section><h2>Acesso de outros sistemas</h2><p>Crie um token somente quando um sistema externo precisar consultar dados de uma empresa.</p>';
            if($accessible){
                $this->form('token');
                $this->select('company_id', 'Empresa', $accessible);
                $this->input('name', 'Nome da integração');
                $this->input('days', 'Validade em dias', 'number', true, '30');
                echo '<fieldset class="pme-api-permissions"><legend>Permissões de leitura</legend><p class="text-muted">Marque somente os dados que a integração precisa consultar. Estas permissões não autorizam alterações.</p>';
                foreach([
                    'context:read'=>['Identificação da empresa','Permite conferir a empresa, a organização e o funcionário vinculados ao token. Não libera clientes nem faturas.'],
                    'clients:read'=>['Clientes','Permite consultar os clientes autorizados para o funcionário vinculado ao token.'],
                    'invoices:read'=>['Faturas','Permite consultar as faturas autorizadas para o funcionário vinculado ao token.'],
                    'events:read'=>['Eventos recebidos','Permite acompanhar o processamento dos eventos recebidos pelas integrações da empresa.'],
                ] as $scope=>$description){
                    echo '<label class="pme-check"><input type="checkbox" name="scopes[]" value="'.$scope.'"><span>'.self::e($description[0]).'<small class="text-muted">'.self::e($description[1]).'</small></span></label>';
                }
                echo '</fieldset><footer class="pme-form-actions"><button class="btn btn-primary">Gerar token</button></footer></form>';
            }else{
                echo '<p class="alert alert-info">Nenhuma empresa ativa com acesso autorizado está disponível. Depois de concluir o provisionamento e vincular o funcionário, você poderá criar uma integração aqui.</p>';
            }
            echo '</section>';
            if ($companies) {
                echo '<section><h2>Receber eventos de outros sistemas</h2><p class="text-muted">Use um webhook quando outro sistema precisar enviar notificações para esta empresa.</p>';
                $this->form('endpoint');
                $this->select('company_id', 'Empresa', $companies);
                $this->selectOptions('provider', 'Autenticação', array_replace(array_combine($this->app->authenticatorNames(), $this->app->authenticatorNames()), ['hmac' => 'HMAC SHA-256']));
                $this->selectOptions('processor', 'Destino', array_replace(array_combine($this->app->processorNames(), $this->app->processorNames()), ['durable_inbox' => 'Caixa de entrada empresarial']));
                echo '<footer class="pme-form-actions"><button class="btn btn-primary">Criar integração</button></footer></form></section>';
            }
            echo '</div>';
            echo '<h2>Tokens de API existentes</h2>';
            $tokens = $db->all('SELECT t.id,t.name,t.company_id,t.expires_at,t.revoked,c.name AS company_name FROM pme_tokens t LEFT JOIN pme_companies c ON c.id=t.company_id WHERE t.user_id=? ORDER BY t.created_at DESC', [$a['id']]);
            if(!$tokens){echo '<p class="pme-empty">Nenhum token criado. Crie um token apenas para uma integração externa.</p>';}
            foreach ($tokens as $t) {
                echo '<article><strong>'.self::e($t['name']).'</strong><p>Empresa: '.self::e($t['company_name']??'Empresa indisponível').'</p> '.((int)$t['revoked'] ? 'Revogado' : 'Válido até '.date('d/m/Y', (int)$t['expires_at']));
                $this->form('revoke_token', ['id' => $t['id']]);
                echo '<button class="btn btn-default" '.((int)$t['revoked']?'disabled':'').'>Revogar token</button></form></article>';
            }
            echo '<h2>Webhooks existentes</h2>';$endpointCount=0;
            foreach ($companies as $c) {
                $endpoints = $db->all('SELECT id,provider,processor,active FROM pme_endpoints WHERE company_id=?', [$c['id']]);
                foreach ($endpoints as $e) {$endpointCount++;
                    echo '<article><strong>'.self::e($c['name'].' · '.$e['provider']).'</strong><small>'.self::e(Entry::url($this->app,$this->app->config['base_url'].'/hooks/'.$e['provider'].'/'.$e['id'])).'</small><p>Situação: '.((int)$e['active']?'Ativo':'Suspenso').' · Destino: '.self::e($e['processor']).'</p>';
                    $this->form('endpoint_active', ['company_id' => $c['id'],'id' => $e['id'],'active' => (int)$e['active'] ? 0 : 1]);
                    echo '<button class="btn btn-default">'.((int)$e['active'] ? 'Suspender' : 'Reativar').'</button></form></article>';
                }
            }
            if(!$endpointCount){echo '<p class="pme-empty">Nenhum webhook configurado. Os eventos recebidos aparecerão na guia Eventos.</p>';}
        } elseif ($tab === 'events') {
            echo '<h1>Fila de eventos</h1><p class="text-muted">A confirmação ao provedor ocorre após a persistência. Eventos divergentes exigem revisão.</p>';
            $this->filters($companies,['pending'=>'Pendente','processing'=>'Em processamento','done'=>'Concluído','dead'=>'Falha definitiva','review'=>'Revisão necessária']);$eventCount=0;
            foreach ($companies as $c) {
                $params=[$c['id']];$where='company_id=?'.$this->filterSql($params,'company_id','provider_event','status');
                $rows = $db->all('SELECT id,provider_event,status,attempts,error_code,created_at FROM pme_events WHERE '.$where.' ORDER BY created_at DESC LIMIT 100', $params);
                if (!$rows) {
                    continue;
                }
                $eventCount+=count($rows);echo '<h2>'.self::e(($c['organization_name']??'').' / '.$c['name']).'</h2><p class="text-muted">Até 100 eventos mais recentes nos filtros selecionados.</p><div class="table-responsive"><table class="table table-striped table-hover"><tr><th>Data</th><th>Evento</th><th>Estado</th><th>Tentativas</th><th>Diagnóstico</th><th></th></tr>';
                foreach ($rows as $r) {
                    echo '<tr><td>'.self::e(date('d/m/Y H:i',(int)$r['created_at'])).'</td><td>'.self::e($r['provider_event']).'</td><td>'.self::e(self::state($r['status'])).'</td><td>'.(int)$r['attempts'].'</td><td>'.self::e($r['error_code']).'</td><td>';
                    if (in_array($r['status'], ['dead','review'], true)) {
                        $this->form('event_retry', ['id' => $r['id']]);
                        echo '<button class="btn btn-default">Reprocessar</button></form>';
                    }
                    echo '</td></tr>';
                }
                echo '</table></div>';
            }
            if(!$eventCount){echo '<div class="pme-empty"><strong>Nenhum evento nos filtros selecionados.</strong><p>Os webhooks configurados em Integrações alimentarão esta fila. Ajuste os filtros para consultar eventos anteriores.</p></div>';}
        } elseif ($tab === 'updates') {
            echo '<h1>Atualizações e backups</h1><p class="text-muted">A versão instalada é reconhecida automaticamente. Antes de atualizar uma base empresarial, o módulo cria um backup e executa as migrações necessárias. As opções manuais abaixo são de uso administrativo.</p>';
            if ($admin) {
                $gate = $db->one('SELECT g.state,r.version FROM pme_gate g LEFT JOIN pme_releases r ON r.id=g.release_id WHERE g.id=1');
                echo '<article><strong>Estado global: '.self::e(self::state($gate['state'])).'</strong><p>Esquema publicado: '.self::e($gate['version']).'</p></article>';
                $releases = $db->all('SELECT id,version,root,created_at FROM pme_releases ORDER BY created_at DESC');
                foreach ($releases as &$r) {
                    $r['name'] = $r['version'].' · '.date('d/m/Y H:i',(int)$r['created_at']);
                }
                unset($r);
                echo '<details><summary>Operações manuais de atualização</summary>';
                $this->form('plan_update');
                $this->select('release_id', 'Versão registrada', $releases);
                echo '<footer class="pme-form-actions"><button class="btn btn-primary">Preparar plano</button></footer></form>';
                foreach ($db->all('SELECT * FROM pme_updates ORDER BY created_at DESC LIMIT 50') as $u) {
                    echo '<article><strong>Plano de '.date('d/m/Y H:i',(int)$u['created_at']).'</strong><details><summary>Identificador técnico</summary>'.self::e($u['id']).'</details><p>Situação: '.self::e(self::state($u['status'])).'</p>';
                    $this->form('execute_update', ['id' => $u['id']]);
                    echo '<button class="btn btn-default">Executar / retomar plano</button></form><ul>';
                    foreach ($db->all('SELECT s.*,c.name FROM pme_update_steps s JOIN pme_companies c ON c.id=s.company_id WHERE s.update_id=?', [$u['id']]) as $step) {
                        echo '<li>'.self::e($step['name'].' — '.self::state($step['status']).' '.$step['error_code']).'</li>';
                    }
                    echo '</ul></article>';
                }
            }
            if ($admin) {
                echo '<details><summary>Registrar código de uma versão do Perfex</summary>';
                $this->form('register_release'); $this->input('root','Diretório absoluto da versão preparada'); echo '<button class="btn btn-primary">Validar e registrar</button></form></details>';
                $this->form('run_jobs'); echo '<footer class="pme-form-actions"><button class="btn btn-default">Executar um ciclo de rotinas agora</button></footer></form></details>';
            }
            if ($companies) {
                echo '<details><summary>Restaurar backup em destino vazio</summary><p>Suspenda a empresa e informe um banco/prefixo novo. O destino anterior é preservado.</p>';
                $this->form('restore'); $this->select('company_id','Empresa',$companies);
                echo '<label class="form-group">Backup concluído<select class="form-control" name="job_id" data-backup-choice><option value="">Selecione um backup</option>';
                foreach($companies as $company){foreach($db->all("SELECT id,created_at FROM pme_jobs WHERE type='backup' AND status='done' AND target=? ORDER BY created_at DESC",[$company['id']]) as $job){echo '<option data-company="'.self::e($company['id']).'" value="'.self::e($job['id']).'">'.self::e($company['name'].' · '.date('d/m/Y H:i:s',(int)$job['created_at'])).'</option>';}}
                echo '</select></label>';
                if ($admin) {
                    echo '<label class="form-group">Backup de atualização (alternativa)<select class="form-control" name="update_id" data-backup-choice><option value="">Não usar</option>';
                    foreach($companies as $company){foreach($db->all("SELECT s.update_id,u.created_at FROM pme_update_steps s JOIN pme_updates u ON u.id=s.update_id WHERE s.company_id=? AND s.backup IS NOT NULL AND s.backup<>'' ORDER BY u.created_at DESC",[$company['id']]) as $step){echo '<option data-company="'.self::e($company['id']).'" value="'.self::e($step['update_id']).'">'.self::e($company['name'].' · '.date('d/m/Y H:i:s',(int)$step['created_at'])).'</option>';}}
                    echo '</select></label>';
                }
                foreach (['host'=>'Servidor','database'=>'Banco vazio','username'=>'Usuário','prefix'=>'Prefixo no destino'] as $k=>$v) { $this->input($k,$v,'text',$k!=='prefix'); }
                echo '<p class="text-muted">Banco exclusivo: deixe o prefixo em branco para restaurar tabelas sem prefixo. Banco compartilhado: informe um prefixo exclusivo.</p>';
                $this->input('port','Porta','number',true,'3306'); $this->input('db_password','Senha','password');
                echo '<button class="btn btn-primary">Restaurar e manter suspensa</button></form></details>';
            }
            echo '<h2>Histórico de backups e rotinas</h2><p class="text-muted">Até 100 rotinas recentes nos filtros selecionados.</p>';
            $this->filters($companies,['pending'=>'Pendente','running'=>'Em execução','done'=>'Concluído','failed'=>'Falha']);
            $params=$admin?[]:[$a['id']];$where=$admin?'1=1':'j.actor_id=?';$where.=$this->filterSql($params,'j.target','j.type','j.status','j.created_at');
            $jobs=$db->all('SELECT j.*,c.name AS company_name FROM pme_jobs j LEFT JOIN pme_companies c ON c.id=j.target WHERE '.$where.' ORDER BY j.created_at DESC LIMIT 100',$params);
            foreach($jobs as &$job){$job['date']=date('d/m/Y H:i:s',(int)$job['created_at']);$job['type']=self::state($job['type']);$job['company_name']=$job['company_name']??'Administração central';$job['status']=self::state($job['status']);}unset($job);
            $this->simpleTable($jobs,['date'=>'Data','company_name'=>'Empresa','type'=>'Rotina','status'=>'Situação','error_code'=>'Diagnóstico']);
        } elseif ($tab === 'audit') {
            echo '<h1>Auditoria</h1><p class="text-muted">Registros com assinatura de integridade e sem senhas ou tokens.</p>';
            $this->filters($companies,[],true);
            $params=$admin?[]:[$a['id']];$where=$admin?'1=1':'a.actor_id=?';$where.=$this->filterSql($params,'a.company_id','a.action',null,'a.created_at');
            if(!empty($_GET['actor'])){$where.=" AND (u.name LIKE ? ESCAPE '!' OR u.email LIKE ? ESCAPE '!')";$term=$this->like((string)$_GET['actor']);array_push($params,$term,$term);}
            $rows=$db->all('SELECT a.*,c.name AS company_name,u.name AS actor_name FROM pme_audit a LEFT JOIN pme_companies c ON c.id=a.company_id LEFT JOIN pme_users u ON u.id=a.actor_id WHERE '.$where.' ORDER BY a.created_at DESC LIMIT 200',$params);
            echo '<p class="text-muted">Até 200 registros recentes nos filtros selecionados.</p>';
            if(!$rows){echo '<p class="pme-empty">Nenhum registro de auditoria nos filtros selecionados.</p>';}
            else{echo '<div class="table-responsive"><table class="table table-striped table-hover"><thead><tr><th>Data</th><th>Responsável</th><th>Empresa</th><th>Ação</th><th>Integridade</th><th>Detalhes</th></tr></thead><tbody>';
                foreach($rows as $row){echo '<tr><td>'.date('d/m/Y H:i:s',(int)$row['created_at']).'</td><td>'.self::e($row['actor_name']??'Sistema').'</td><td>'.self::e($row['company_name']??($row['company_id']?'Empresa indisponível':'Administração central')).'</td><td>'.self::e(self::actionName($row['action'])).'</td><td>'.($this->app->audit->verify($row)?'Válida':'Divergente').'</td><td><details><summary>Dados técnicos</summary><pre>'.self::e(json_encode(['action'=>$row['action'],'company_id'=>$row['company_id'],'actor_id'=>$row['actor_id'],'details'=>$row['details']??$row['payload']??null],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)).'</pre></details></td></tr>';}
                echo '</tbody></table></div>';
            }
        } else {
            throw new Failure('tab', 'Página não encontrada.', 404);
        }
        echo '</div>';
    }
    private function filters(array $companies,array $states=[],bool $actor=false): void
    {
        echo '<form method="get" class="pme-report-filters"><input type="hidden" name="tab" value="'.self::e($_GET['tab']??'').'">';
        echo '<label>Empresa<select class="form-control" name="company_id"><option value="">Todas as autorizadas</option>';
        foreach($companies as $company){echo '<option value="'.self::e($company['id']).'" '.(($company['id']===($_GET['company_id']??''))?'selected':'').'>'.self::e(($company['organization_name']??'').' / '.$company['name']).'</option>';}echo '</select></label>';
        if($states){echo '<label>Situação<select class="form-control" name="status"><option value="">Todas</option>';foreach($states as $key=>$label){echo '<option value="'.self::e($key).'" '.($key===($_GET['status']??'')?'selected':'').'>'.self::e($label).'</option>';}echo '</select></label>';}
        foreach(array_merge(['q'=>['Pesquisar','search'],'from'=>['De','date'],'to'=>['Até','date']],$actor?['actor'=>['Responsável (nome ou e-mail)','search']]:[]) as $key=>[$label,$type]){echo '<label>'.self::e($label).'<input class="form-control" name="'.$key.'" type="'.$type.'" value="'.self::e($_GET[$key]??'').'"></label>';}
        echo '<button class="btn btn-primary">Filtrar</button><a class="btn btn-default" href="?tab='.rawurlencode((string)($_GET['tab']??'')).'">Limpar</a></form>';
    }
    private function like(string $value): string {return '%'.str_replace(['!','%','_'],['!!','!%','!_'],mb_substr(trim($value),0,120)).'%';}
    private function filterSql(array &$params,string $company,string $text,?string $status,string $date='created_at'): string
    {
        $sql='';
        foreach(['company_id'=>$company,'status'=>$status] as $key=>$column){if($column&&!empty($_GET[$key])){$sql.=' AND '.$column.'=?';$params[]=(string)$_GET[$key];}}
        if(!empty($_GET['q'])){$sql.=" AND $text LIKE ? ESCAPE '!'";$params[]=$this->like((string)$_GET['q']);}
        foreach(['from'=>'>=','to'=>'<'] as $key=>$operator){$value=(string)($_GET[$key]??'');if($value==='')continue;$d=\DateTimeImmutable::createFromFormat('!Y-m-d',$value);if(!$d||$d->format('Y-m-d')!==$value){throw new Failure('date','Informe um período válido.');}$sql.=' AND '.$date.$operator.'?';$params[]=($key==='to'?$d->modify('+1 day'):$d)->getTimestamp();}
        return $sql;
    }
    private static function state(string $state): string
    {
        return ['active'=>'Ativo','suspended'=>'Suspenso','pending'=>'Pendente','running'=>'Em execução','processing'=>'Em processamento','done'=>'Concluído','failed'=>'Falha','dead'=>'Falha definitiva','review'=>'Revisão necessária','maintenance'=>'Em manutenção','planned'=>'Planejado','ready'=>'Pronto','cancelled'=>'Cancelado','backup'=>'Backup','update'=>'Atualização','restore'=>'Restauração','queued'=>'Na fila'][$state]??$state;
    }
    private static function actionName(string $action): string
    {
        return ['global_admin.entered'=>'Entrada administrativa global','global_admin.local_role'=>'Perfil administrativo local preparado','backup.created'=>'Backup criado','backup.restore_verified'=>'Restauração de backup verificada','certificate.accessed'=>'Certificado acessado','certificate.imported'=>'Certificado importado','certificate.removed'=>'Certificado removido','company.entered'=>'Acesso à empresa','company.onboarding_completed'=>'Provisionamento concluído','company.profile_updated'=>'Dados cadastrais atualizados','company.provision_failed'=>'Falha no provisionamento','company.restore_verified'=>'Restauração da empresa verificada','company.returned_to_installation'=>'Retorno à instalação principal','company.switched_from_installation'=>'Alternância a partir da principal','company.updated'=>'Empresa atualizada','core.company_updated'=>'Estrutura da empresa atualizada','core.retry_pending'=>'Retomada da estrutura pendente','core.rollback_completed'=>'Reversão da estrutura concluída','core.synchronization_pending'=>'Sincronização da estrutura pendente','core.synchronized'=>'Estrutura sincronizada','core.workflow_retry_pending'=>'Retomada do processo pendente','cron.failed'=>'Falha em rotina agendada','endpoint.created'=>'Webhook criado','event.failed'=>'Falha em evento','event.retried'=>'Evento reenviado à fila','installation.organization_changed'=>'Organização da principal alterada','job.failed'=>'Falha em rotina','logout.all'=>'Encerramento das sessões','module.updated'=>'Módulo atualizado','module.update_pending'=>'Atualização de módulo pendente','module.failed'=>'Falha em módulo','module.release_reviewed'=>'Versão do módulo revisada','module.review_refreshed'=>'Revisão do módulo atualizada','module.state_changed'=>'Situação do módulo alterada','module.toggle_failed'=>'Falha na ativação ou desativação','module.uninstalled'=>'Módulo desinstalado','organization.updated'=>'Organização atualizada','schema.dependencies_deferred'=>'Dependências de estrutura adiadas','schema.dependencies_verified'=>'Dependências verificadas','settings.updated'=>'Configurações atualizadas','staff.company_access_updated'=>'Empresas do colaborador atualizadas','update.failed'=>'Falha na atualização','update.planned'=>'Atualização planejada','update.published'=>'Atualização publicada','company.created'=>'Empresa criada','company.renamed'=>'Empresa renomeada','company.status'=>'Situação da empresa alterada','company.removed'=>'Empresa excluída','membership.granted'=>'Acesso concedido','membership.revoked'=>'Acesso revogado','organization.created'=>'Organização criada','organization.admin'=>'Administrador de organização autorizado','organization.admin_revoked'=>'Administração revogada','module.available'=>'Módulo disponibilizado','module.approved'=>'Módulo ativado','module.disabled'=>'Módulo desativado','company.switched'=>'Empresa alternada','installation.configured'=>'Vínculo da instalação alterado','token.issued'=>'Token de API criado','token.revoked'=>'Token revogado'][$action]??ucfirst(str_replace(['.','_'],' ',$action));
    }
    private function form(string $action, array $hidden = []): void
    {
        echo form_open(current_url().'?tab='.rawurlencode((string)($_GET['tab']??'companies'))).'<input type="hidden" name="action" value="'.self::e($action).'">';
        foreach ($hidden as $k => $v) {
            echo '<input type="hidden" name="'.self::e($k).'" value="'.self::e($v).'">';
        }
    }
    private function input(string $name, string $label, string $type = 'text', bool $required = true, string $value = ''): void
    {
        echo '<label class="form-group">'.self::e($label).'<input class="form-control" name="'.self::e($name).'" type="'.$type.'" '.($required ? 'required ' : '').'value="'.self::e($value).'" '.($type === 'password' ? 'autocomplete="new-password"' : '').'></label>';
    }
    private function select(string $name, string $label, array $rows): void
    {
        $values = [];
        foreach ($rows as $r) {
            $values[$r['id']] = (!empty($r['organization_name'])?$r['organization_name'].' / ':'').$r['name'];
        }
        $this->selectOptions($name, $label, $values);
    }
    private function selectOptions(string $name, string $label, array $values): void
    {
        echo '<label class="form-group">'.self::e($label).'<select class="form-control" name="'.$name.'"'.($name==='release_id'?'':' required').'>';
        foreach ($values as $key => $value) {
            echo '<option value="'.self::e($key).'">'.self::e($value).'</option>';
        }
        echo '</select></label>';
    }
    private function simpleTable(array $rows, array $columns): void
    {
        if (!$rows) {
            echo '<p class="text-muted">Nenhum registro disponível.</p>';
            return;
        }
        echo '<div class="table-responsive"><table class="table table-striped table-hover"><tr>';
        foreach ($columns as $label) {
            echo '<th>'.self::e($label).'</th>';
        }
        echo '</tr>';
        foreach ($rows as $r) {
            echo '<tr>';
            foreach ($columns as $key => $label) {
                echo '<td>'.self::e($r[$key] ?? '').'</td>';
            }
            echo '</tr>';
        }
        echo '</table></div>';
    }
    private static function e(mixed $v): string
    {
        return Response::escape($v);
    }
}
