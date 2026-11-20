<?php
// Loaded before the native URL helper in an isolated company or the principal alias.
(defined('PME_CONTEXT_READY') || defined('PME_PRIMARY_ROUTE')) or exit;
if(!function_exists('base_url')){function base_url($uri='', $protocol=null){return \Multiempresa\Http\Entry::nativeUrl($uri,$protocol);}}
if(!function_exists('site_url')){function site_url($uri='', $protocol=null){return \Multiempresa\Http\Entry::nativeUrl($uri,$protocol,true);}}
