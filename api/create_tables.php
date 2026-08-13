<?php
// This file is kept for backward compatibility but goecom.php is the primary DB init.
// Redirects to goecom.php

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/auth.php';

setApiHeaders();
requireAdminAuth();

// Forward to goecom.php
require_once __DIR__ . '/goecom.php';
