<?php
$_GET['action'] = 'list';
session_start();
$_SESSION = ['user_id' => 1, 'user_role' => 'Administrator'];
include __DIR__ . '/php/branches.php';
