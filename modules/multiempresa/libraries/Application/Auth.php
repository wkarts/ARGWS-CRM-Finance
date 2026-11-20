<?php

declare(strict_types=1);

namespace Multiempresa\Application;

use Multiempresa\Domain\{Id,Failure};

final class Auth
{
    public function __construct(private App $app)
    {
    }
    /** Only a successfully authenticated native Perfex staff identity can call this bridge. */
    public function syncNative(int $staffId): array
    {
        $table=\Multiempresa\Domain\Id::identifier($this->app->config['native_prefix'].'staff');
        $q=$this->app->db->pdo->prepare('SELECT staffid,email,firstname,lastname,password,active,admin,two_factor_auth_enabled FROM `'.$table.'` WHERE staffid=?');
        $q->execute([$staffId]); $native=$q->fetch(\PDO::FETCH_ASSOC);
        $existing=$this->app->db->one('SELECT * FROM pme_users WHERE native_staff_id=?',[$staffId]);
        if (!$native || !(int)$native['active']) {
            if ($existing) { $this->app->db->exec('UPDATE pme_users SET active=0,auth_version=auth_version+1 WHERE id=? AND active=1',[$existing['id']]); }
            throw new Failure('native_staff_disabled','Funcionário central inativo.',403);
        }
        $fingerprint=$this->app->vault->mac(json_encode([$native['password'],$native['email'],$native['admin'],$native['two_factor_auth_enabled']],JSON_THROW_ON_ERROR));
        if (!$existing) {
            $id=Id::new();
            $this->app->db->exec('INSERT INTO pme_users(id,native_staff_id,native_fingerprint,email,name,password_hash,platform_admin,created_at) VALUES(?,?,?,?,?,?,?,?)',[$id,$staffId,$fingerprint,Id::email($native['email']),trim($native['firstname'].' '.$native['lastname']),'native-authentication-only',(int)$native['admin'],time()]);
        } else {
            $id=$existing['id'];
            $changed=!hash_equals((string)$existing['native_fingerprint'],$fingerprint) || !(int)$existing['active'];
            $this->app->db->exec('UPDATE pme_users SET native_fingerprint=?,email=?,name=?,platform_admin=?,active=1,auth_version=auth_version+? WHERE id=?',[$fingerprint,Id::email($native['email']),trim($native['firstname'].' '.$native['lastname']),(int)$native['admin'],(int)$changed,$id]);
            if ($changed) { $this->app->db->exec('UPDATE pme_tokens SET revoked=1 WHERE user_id=?',[$id]); }
        }
        $actor=$this->app->db->one('SELECT * FROM pme_users WHERE id=?',[$id]);
        $actor['mfa']=(int)$native['two_factor_auth_enabled']>0 ? 1 : 0;
        return $actor;
    }
    public function refresh(string $id): array
    {
        $u=$this->app->db->one('SELECT native_staff_id FROM pme_users WHERE id=?',[$id]);
        if (!$u || !$u['native_staff_id']) { throw new Failure('native_identity','Identidade central inválida.',403); }
        return $this->syncNative((int)$u['native_staff_id']);
    }
    public function createSession(array $u, ?string $company, bool $mfa): array
    {
        $token = Id::token();
        $csrf = Id::token();
        $expires = time() + ($this->app->config['session_ttl'] ?? 28800);
        $this->app->db->exec('INSERT INTO pme_sessions(token_hash,user_id,company_id,auth_version,csrf,mfa,expires_at) VALUES(?,?,?,?,?,?,?)', [hash('sha256', $token),$u['id'],$company,(int)$u['auth_version'],$csrf,(int)$mfa,$expires]);
        return ['token' => $token,'csrf' => $csrf,'expires_at' => $expires];
    }
    public function session(?string $token, ?string $company = null): ?array
    {
        if (!$token || strlen($token) > 128) {
            return null;
        }
        $s = $this->app->db->one('SELECT s.*,u.id,u.email,u.name,u.platform_admin,u.totp FROM pme_sessions s JOIN pme_users u ON u.id=s.user_id WHERE s.token_hash=? AND s.expires_at>? AND u.active=1 AND s.auth_version=u.auth_version', [hash('sha256', $token),time()]);
        if (!$s || $s['company_id'] !== $company) {
            return null;
        }
        $fresh=$this->refresh($s['user_id']);
        if ((int)$fresh['auth_version'] !== (int)$s['auth_version']) { return null; }
        if ($company) {
            $m = $this->app->catalog->membership($s['user_id'], $company);
            $s['staff_id'] = (int)$m['staff_id'];
        }
        return $s;
    }
    public function csrf(array $session, ?string $provided): void
    {
        if (!$provided || !hash_equals($session['csrf'], $provided)) {
            throw new Failure('csrf', 'A sessão do formulário expirou. Recarregue a página.', 403);
        }
    }
    public function logout(array $session): void
    {
        $this->app->db->transaction(function () use ($session) {
            $this->app->db->exec('UPDATE pme_users SET auth_version=auth_version+1 WHERE id=?', [$session['id']]);
            $this->app->db->exec('DELETE FROM pme_sessions WHERE user_id=?', [$session['id']]);
            $this->app->audit->log('logout.all', $session['id']);
        });
    }
    public function issueTicket(array $session, string $company, string $challenge): string
    {
        if (!preg_match('/\A[a-f0-9]{64}\z/', $challenge)) {
            throw new Failure('challenge', 'Desafio SSO inválido.');
        }
        $this->refresh($session['id']);
        $this->app->catalog->membership($session['id'], $company);
        $c = $this->app->catalog->company($company);
        if ($c['status'] !== 'active') {
            throw new Failure('maintenance', 'Empresa indisponível.', 503);
        }
        $token = Id::token();
        $this->app->db->exec('INSERT INTO pme_tickets(token_hash,user_id,company_id,challenge,auth_version,mfa,expires_at) VALUES(?,?,?,?,?,?,?)', [hash('sha256', $token),$session['id'],$company,$challenge,$session['auth_version'],$session['mfa'],time() + 90]);
        return $token;
    }
    public function consumeTicket(string $code, string $company, string $verifier): array
    {
        return $this->app->db->transaction(function () use ($code, $company, $verifier) {
            $hash = hash('sha256', $code);
            $row = $this->app->db->one('SELECT t.*,u.id,u.active,u.auth_version AS current_version FROM pme_tickets t JOIN pme_users u ON u.id=t.user_id WHERE t.token_hash=?', [$hash]);
            if (!$row || !(int)$row['active'] || (int)$row['auth_version'] !== (int)$row['current_version'] || $row['company_id'] !== $company || (int)$row['expires_at'] < time() || !hash_equals($row['challenge'], hash('sha256', $verifier)) || $this->app->db->exec('UPDATE pme_tickets SET consumed=1 WHERE token_hash=? AND consumed=0', [$hash]) !== 1) {
                throw new Failure('sso_ticket', 'Autorização de acesso inválida ou expirada.', 403);
            }
            $fresh=$this->refresh($row['user_id']);
            if ((int)$fresh['auth_version'] !== (int)$row['auth_version']) { throw new Failure('sso_ticket','Identidade alterada.',403); }
            $this->app->catalog->membership($row['user_id'], $company);
            $s = $this->createSession($row, $company, (bool)$row['mfa']);
            (new StaffAccess($this->app))->remember($row['user_id'],$company);
            $this->app->audit->log('company.entered', $row['user_id'], $company);
            return $s;
        });
    }
    public function issueToken(array $actor, string $company, string $name, array $scopes, int $days): string
    {
        $this->app->catalog->membership($actor['id'], $company);
        $allowed = ['context:read','clients:read','invoices:read','events:read'];
        if (!$scopes || array_diff($scopes, $allowed) || $days < 1 || $days > 365) {
            throw new Failure('token_scope', 'Selecione ao menos uma permissão válida e informe uma validade entre 1 e 365 dias.');
        }
        if (in_array('events:read', $scopes, true)) {
            $this->app->catalog->assertManager($actor, $this->app->catalog->company($company)['organization_id']);
        }
        $raw = 'pme_'.Id::token();
        $this->app->db->exec('INSERT INTO pme_tokens(id,token_hash,company_id,user_id,name,scopes,mfa,expires_at,created_at) VALUES(?,?,?,?,?,?,?,?,?)', [Id::new(),hash('sha256', $raw),$company,$actor['id'],Catalog::name($name),json_encode(array_values($scopes), JSON_THROW_ON_ERROR),(int)($actor['mfa'] ?? 0),time() + $days * 86400,time()]);
        $this->app->audit->log('token.issued', $actor['id'], $company, ['scopes' => $scopes]);
        return $raw;
    }
    public function token(string $raw, string $scope, ?string $company = null): array
    {
        $t = $this->app->db->one('SELECT t.*,u.active FROM pme_tokens t JOIN pme_users u ON u.id=t.user_id WHERE t.token_hash=? AND t.revoked=0 AND t.expires_at>? AND u.active=1', [hash('sha256', $raw),time()]);
        if (!$t || ($company !== null && $t['company_id'] !== $company) || !in_array($scope, json_decode($t['scopes'], true, 32, JSON_THROW_ON_ERROR), true)) {
            throw new Failure('api_token', 'Token sem autorização para este recurso.', 403);
        }
        $this->refresh($t['user_id']);
        $current=$this->app->db->one('SELECT revoked FROM pme_tokens WHERE id=?',[$t['id']]);
        if (!$current || (int)$current['revoked']) { throw new Failure('api_token','Token revogado.',403); }
        $m = $this->app->catalog->membership($t['user_id'], $t['company_id']);
        if ($scope === 'events:read') {
            $actor = $this->app->db->one('SELECT id,platform_admin FROM pme_users WHERE id=?', [$t['user_id']]);
            $this->app->catalog->assertManager($actor, $this->app->catalog->company($t['company_id'])['organization_id']);
        }
        $t['staff_id'] = (int)$m['staff_id'];
        $this->app->limits->hit('api:'.$t['id'], 120, 60);
        return $t;
    }
}
