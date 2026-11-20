<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__).'/autoload.php';
use Multiempresa\Infrastructure\SqlScript;
$server=new PDO(getenv('MULTIEMPRESA_TEST_DSN'),getenv('MULTIEMPRESA_TEST_USER')?:'root',getenv('MULTIEMPRESA_TEST_PASSWORD')?:'',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$db=getenv('MULTIEMPRESA_TEST_DATABASE');
if (!preg_match('/^multiempresa_test_[a-z0-9]+$/',$db)) { throw new RuntimeException('Use a disposable test database name.'); }
$server->exec('CREATE DATABASE `'.$db.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$server->exec('USE `'.$db.'`');
SqlScript::import($server,file_get_contents(dirname(__DIR__).'/resources/perfex-341.sql'),'tbl','core_');
$q=$server->prepare('INSERT INTO core_staff(email,firstname,lastname,password,datecreated,admin,active) VALUES(?,?,?,?,?,1,1)');
$q->execute(['admin@example.test','Teste','Administrador',password_hash('Native-test-password-26',PASSWORD_BCRYPT),date('Y-m-d H:i:s')]);
$server->exec("UPDATE core_options SET value='Principal' WHERE name='companyname'");
echo json_encode(['database'=>$db]);
