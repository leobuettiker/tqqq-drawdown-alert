<?php
require_once __DIR__ . '/../../app/functions.php';
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Confirm Buy</title>
<style>
body{margin:0;min-height:100vh;display:grid;place-items:center;background:#eef2ff;font-family:system-ui,sans-serif}
.card{width:min(420px,calc(100% - 32px));background:white;border-radius:24px;padding:28px;box-shadow:0 20px 60px rgba(15,23,42,.12)}
button,input{width:100%;box-sizing:border-box;padding:14px;border-radius:14px;font:inherit}
input{border:1px solid #cbd5e1;margin-top:12px}
button{margin-top:16px;border:0;background:#4f46e5;color:white;font-weight:700}
</style>
</head>
<body>
<div class="card">
<h1>Protected confirmation area</h1>
<p>Minimal placeholder confirmation UI.</p>
<form method="post">
<input type="password" placeholder="Confirmation password">
<button type="submit">Authenticate</button>
</form>
</div>
</body>
</html>
