<?php
// Check for password error in the URL
$error = $_GET['error'] ?? '';
$attempt = $_GET['attempt'] ?? 0;
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Tool Hub</title>

  <style>
    * {
      box-sizing: border-box;
    }

    body {
      margin: 0;
      font-family: Arial, sans-serif;
      background: #111827 url('/images/back1.jpg') no-repeat center center fixed;
      background-size: cover;
      color: #f3f4f6;
      min-height: 100vh;
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 40px 20px;
    }

    .container {
      width: 100%;
      max-width: 700px;
      background-color: rgba(17, 24, 39, 0.8); /* Semi-transparent background for readability */
      padding: 20px;
      border-radius: 10px;
    }

    h1 {
      text-align: center;
      margin-bottom: 10px;
      font-size: 42px;
    }

    p {
      text-align: center;
      color: #9ca3af;
      margin-bottom: 40px;
    }

    .grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
      gap: 20px;
    }

    .card {
      background: #1f2937;
      border: 1px solid #374151;
      border-radius: 14px;
      padding: 24px;
      text-decoration: none;
      color: #f3f4f6;
      transition: 0.2s ease;
    }

    .card:hover {
      transform: translateY(-4px);
      border-color: #60a5fa;
      background: #263244;
    }

    .card h2 {
      margin-top: 0;
      margin-bottom: 10px;
      font-size: 22px;
    }

    .card p {
      margin: 0;
      text-align: left;
      color: #9ca3af;
      font-size: 14px;
      line-height: 1.5;
    }

    footer {
      margin-top: 40px;
      text-align: center;
      color: #6b7280;
      font-size: 13px;
    }
  </style>
</head>
<body>
  <div class="container">
    <!-- Clock -->
    <div id="clock" style="text-align: center; font-size: 120px; font-weight: bold; color: white; margin-bottom: 20px;"></div>

    <h1>Tool Hub</h1>
    <?php if ($error === 'invalid_password'): ?>
    <div style="color: #ef4444; text-align: center; margin-bottom: 20px;">
        Incorrect password. Please try again.
    </div>
    <?php endif; ?>


    <p>Quick access to internal tools and utilities.</p>

    <div class="grid">
      <a class="card" href="/configGenerator/">
        <h2>Config Generator</h2>
        <p>Create and manage configuration files quickly.</p>
      </a>

      <a class="card" href="/csr/">
        <h2>CSR Generator</h2>
        <p>Generate SSL CSRs and private key commands.</p>
      </a>

      <a class="card" href="/sqlgen/">
        <h2>SQL Generator</h2>
        <p>Generate SQL queries and snippets visually.</p>
      </a>

     <!-- Family Tree links with password prompt -->
      <a class="card" href="#" onclick="showPasswordPrompt('/familytree/'); return false;">
        <h2>Family Tree</h2>
        <p>Interactive family tree builder and editor.</p>
      </a>


      <a class="card" href="familytree_public/">
        <h2>Family Tree</h2>
        <p>Public version of interactive family tree builder and editor.</p>
      </a>
    </div>

    <footer>
      © 2026 Tool Hub
    </footer>
  </div>

  <!-- Scripts -->
  <script>
    // Clock script
    function updateClock() {
      const clockElement = document.getElementById('clock');
      const currentTime = new Date();
      const hours = currentTime.getHours().toString().padStart(2, '0');
      const minutes = currentTime.getMinutes().toString().padStart(2, '0');
      const seconds = currentTime.getSeconds().toString().padStart(2, '0');
      clockElement.innerHTML = `${hours}:${minutes}:${seconds}`;
    }
    setInterval(updateClock, 1000);
    updateClock();

    // Password prompt script
     function showPasswordPrompt(redirectUrl) {
    const password = prompt("Please enter the password to access this page:");
    if (password) {
      const form = document.createElement('form');
      form.method = 'POST';
      form.action = '/pass-check.php';
      form.style.display = 'none';

      const passwordInput = document.createElement('input');
      passwordInput.type = 'hidden';
      passwordInput.name = 'password';
      passwordInput.value = password;

      const redirectInput = document.createElement('input');
      redirectInput.type = 'hidden';
      redirectInput.name = 'redirect_url';
      redirectInput.value = redirectUrl;

      form.appendChild(passwordInput);
      form.appendChild(redirectInput);
      document.body.appendChild(form);
      form.submit();
    }
  }
  </script>
</body>
</html>
