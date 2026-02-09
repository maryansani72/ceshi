<?php
session_start();

// 连接主站数据库 zm (用于读取账号、权限和全局配置)
$host = '127.0.0.1';
$db   = 'zm';
$user = 'zm'; // 建议改为你 zm 数据库的用户名
$pass = 'zm123456'; // 建议改为你 zm 数据库的密码
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
     $pdo = new PDO($dsn, $user, $pass, $options);
} catch (\PDOException $e) {
     throw new \PDOException($e->getMessage(), (int)$e->getCode());
}