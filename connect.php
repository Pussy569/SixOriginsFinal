<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Connect to Server</title>
    <style>
        * {
            box-sizing: border-box;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0;
        }

        .container {
            background: white;
            padding: 2rem;
            border-radius: 12px;
            box-shadow: 0 10px 25px rgba(0,0,0,0.2);
            width: 100%;
            max-width: 400px;
            text-align: center;
        }

        h2 {
            color: #333;
            margin-bottom: 1.5rem;
            font-weight: 600;
        }

        input[type="text"] {
            width: 100%;
            padding: 12px;
            margin-bottom: 1.2rem;
            border: 2px solid #e1e1e1;
            border-radius: 8px;
            outline: none;
            transition: border-color 0.3s;
        }

        input[type="text"]:focus {
            border-color: #764ba2;
        }

        button {
            width: 100%;
            padding: 12px;
            background-color: #764ba2;
            color: white;
            border: none;
            border-radius: 8px;
            font-weight: bold;
            cursor: pointer;
            transition: background 0.3s, transform 0.1s;
        }

        button:hover {
            background-color: #5a3782;
        }

        button:active {
            transform: scale(0.98);
        }
    </style>
</head>
<body>

<div class="container">
    <h2>Server Portal</h2>

    <form method="POST">
        <input type="text" name="server_ip" placeholder="Enter Server IP (e.g. 192.168.1.1)" required>
        <button type="submit" name="connect">CONNECT TO SERVER</button>
    </form>

    <?php
    if(isset($_POST['connect'])){
        // Basic sanitization to prevent header injection
        $ip = filter_var($_POST['server_ip'], FILTER_SANITIZE_URL);
        header("Location: http://$ip/Finals/login.php");
        exit;
    }
    ?>
</div>

</body>
</html>