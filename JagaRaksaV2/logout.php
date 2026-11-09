<?php
require_once __DIR__ . '/config.php';
// Kosongkan data sesi lalu ganti ID sesi (lebih andal daripada session_destroy + session_start).
$_SESSION = [];
session_regenerate_id(true);
flash('success', 'Anda telah keluar dari sistem.');
redirect('index.php');
