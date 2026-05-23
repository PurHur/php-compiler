<h1>Hello <?php echo htmlspecialchars($_REQUEST['name'] ?? 'World'); ?></h1>
<p>Parity with <a href="/index.php/hello?name=World">001-SimpleWeb</a> greet route.</p>
