<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>SaaS Travel System</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<style>
body {
    margin: 0;
    font-family: Arial;
    background: #f5f7fb;
    color: #16395f;
}

.header {
    display: flex;
    justify-content: space-between;
    padding: 20px 40px;
    background: #fff;
    border-bottom: 1px solid #ddd;
}

.logo {
    font-weight: bold;
    font-size: 20px;
}

.nav a {
    margin-left: 15px;
    text-decoration: none;
    font-weight: bold;
    color: #16395f;
}

.hero {
    text-align: center;
    padding: 80px 20px;
}

.hero h1 {
    font-size: 36px;
    margin-bottom: 10px;
}

.hero p {
    font-size: 18px;
    color: #6a7f96;
}

.btn {
    display: inline-block;
    margin-top: 20px;
    padding: 12px 20px;
    background: #0d6efd;
    color: #fff;
    text-decoration: none;
    border-radius: 8px;
}

.features {
    display: grid;
    grid-template-columns: repeat(3,1fr);
    gap: 20px;
    padding: 40px;
}

.card {
    background: #fff;
    padding: 20px;
    border-radius: 12px;
    border: 1px solid #ddd;
}

.footer {
    text-align: center;
    padding: 20px;
    font-size: 14px;
    color: #888;
}
</style>
</head>
<body>

<div class="header">
    <div class="logo">Travel SaaS</div>
    <div class="nav">
        <a href="/public/login.php">Login</a>
        <a href="/public/register.php">Register</a>
    </div>
</div>

<div class="hero">
    <h1>Manage Your Travel Business</h1>
    <p>Hotels, bookings, clients, and invoices — all in one system</p>
    <a class="btn" href="/register.php">Start Free</a>
</div>

<div class="features">
    <div class="card">
        <h3>Hotel Management</h3>
        <p>Create and manage hotel bookings easily</p>
    </div>

    <div class="card">
        <h3>Clients & Suppliers</h3>
        <p>Organize your customers and providers</p>
    </div>

    <div class="card">
        <h3>Reports & Profit</h3>
        <p>Track revenue and performance</p>
    </div>
</div>

<div class="footer">
    © <?= date('Y') ?> Travel SaaS System
</div>

</body>
</html>