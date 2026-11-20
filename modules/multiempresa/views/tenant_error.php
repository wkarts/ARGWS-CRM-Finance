<?php declare(strict_types=1); if(!isset($message)||!is_string($message)){http_response_code(404);exit;} ?>
<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Não foi possível continuar</title>
<style>body{margin:0;background:#f5f6f8;color:#334155;font:16px/1.6 system-ui,sans-serif}main{box-sizing:border-box;max-width:560px;margin:12vh auto;padding:28px;background:#fff;border:1px solid #e2e8f0;border-radius:6px}h1{font-size:22px;margin:0 0 12px}p{margin:0}@media(max-width:600px){main{margin:32px 16px;padding:22px}}</style></head>
<body><main><h1>Não foi possível continuar</h1><p><?= htmlspecialchars($message,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8') ?></p></main></body></html>
