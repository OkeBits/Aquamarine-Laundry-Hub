<?php
require_once __DIR__ . '/app/config.php';

session_unset();
session_destroy();

header('Location: login.php');
exit;
