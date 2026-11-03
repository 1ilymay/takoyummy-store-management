<?php
/** @var mysqli $conn */
session_start();
include 'includes/config.php';
include 'includes/auth.php';

if (is_logged_in()) {
    $home = ($_SESSION['user_role'] ?? '') === 'admin'
        ? '/takoyaki-shop-infoman/admin/flavors/index.php'
        : '/takoyaki-shop-infoman/menu.php';
    header('Location: ' . $home);
    exit();
}

$featuredFlavors = [
    ['name' => 'Original Takoyaki', 'description' => 'Classic savory bites with crispy edges and rich sauce.'],
    ['name' => 'Cheese Takoyaki', 'description' => 'Golden, melty, and irresistibly creamy.'],
    ['name' => 'Spicy Takoyaki', 'description' => 'A bolder kick finished with chili glaze.'],
    ['name' => 'Mentaiko Takoyaki', 'description' => 'Deep savory flavor with a smooth seafood finish.'],
];

if (isset($conn) && $conn) {
    $featuredResult = mysqli_query($conn, 'SELECT name, description, image FROM flavor WHERE is_available = 1 ORDER BY name LIMIT 4');
    if ($featuredResult && mysqli_num_rows($featuredResult) > 0) {
        $featuredFlavors = [];
        while ($row = mysqli_fetch_assoc($featuredResult)) {
            $featuredFlavors[] = $row;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Takoyaki Shop</title>
    <link rel="stylesheet" href="/takoyaki-shop-infoman/includes/style.css">
</head>
<body class="landing-body">
<div class="topline"></div>
<div class="landing-shell">
    <header class="welcome-header">
        <div class="header-inner">
            <a class="brand" href="/takoyaki-shop-infoman/" style="text-decoration:none;">
                <div class="brand-mark">
                    <img src="/takoyaki-shop-infoman/images/takoyaki_logo.jpg" alt="Takoyummy Logo">
                </div>
                <div>
                    <strong>Takoyummy</strong>
                    <small>Fresh from our griddle</small>
                </div>
            </a>

            <nav class="nav">
                <a href="/takoyaki-shop-infoman/">Home</a>
                <a href="/takoyaki-shop-infoman/#flavors">Flavors</a>
                <a href="/takoyaki-shop-infoman/#about">About</a>
                <a href="/takoyaki-shop-infoman/#contact">Contact</a>
            </nav>

            <div class="header-actions">
                <a class="btn ghost" href="/takoyaki-shop-infoman/login.php">Sign In</a>
                <a class="btn light" href="/takoyaki-shop-infoman/register.php">Create Account</a>
            </div>
        </div>
    </header>

    <main class="landing-main" id="home">
        <section class="hero-banner welcome-hero">
            <div class="hero-copy">
                <div class="eyebrow">Freshly made daily</div>
                <h1>Hot, Fresh, and Made to Make You Smile!</h1>
                <p>Takoyaki Shop serves warm, golden bites with crispy edges, rich sauces, and the kind of comfort that feels like home.</p>
                <div class="hero-actions">
                    <a class="btn" href="/takoyaki-shop-infoman/login.php">Order Now</a>
                    <a class="btn secondary" href="/takoyaki-shop-infoman/register.php">Create account</a>
                </div>
                <div class="hero-stats">
                    <div><strong>4.9★</strong><span>Customer love</span></div>
                    <div><strong>15 min</strong><span>Average prep</span></div>
                    <div><strong>100%</strong><span>Fresh grilled</span></div>
                </div>
            </div>
        </section>

        <section class="feature-strip">
            <article>
                <span>🌭</span>
                <div>
                    <strong>Signature Bites</strong>
                    <small>Original, cheesy, and spicy favorites</small>
                </div>
            </article>
            <article>
                <span>🔥</span>
                <div>
                    <strong>Freshly Grilled</strong>
                    <small>Made to order with a crisp finish</small>
                </div>
            </article>
            <article>
                <span>🚚</span>
                <div>
                    <strong>Fast Delivery</strong>
                    <small>Ready for pickup or doorstep delivery</small>
                </div>
            </article>
        </section>

        <section class="content-section" id="flavors">
            <div class="section-header">
                <div>
                    <div class="eyebrow">Featured flavors</div>
                    <h2>Popular Takoyaki Picks</h2>
                </div>
                <a href="/takoyaki-shop-infoman/login.php" class="btn ghost">View menu</a>
            </div>

            <div class="flavor-grid">
                <?php foreach ($featuredFlavors as $flavor) { ?>
                    <article class="flavor-card">
                        <div class="flavor-image">
                            <?php if (!empty($flavor['image'])) { ?>
                                <img src="/takoyaki-shop-infoman/<?= htmlspecialchars($flavor['image']) ?>" alt="<?= htmlspecialchars($flavor['name']) ?>">
                            <?php } else { ?>
                                <img src="/takoyaki-shop-infoman/images/takoyaki_logo.jpg" alt="<?= htmlspecialchars($flavor['name']) ?>">
                            <?php } ?>
                        </div>
                        <div class="flavor-info">
                            <h3><?= htmlspecialchars($flavor['name']) ?></h3>
                            <p><?= htmlspecialchars($flavor['description']) ?></p>
                            <span class="pill">Best seller</span>
                        </div>
                    </article>
                <?php } ?>
            </div>
        </section>

        <section class="promo-row">
            <div class="promo-card large">
                <div>
                    <div class="eyebrow">Limited-time offer</div>
                    <h3>Enjoy a sizzling combo for sharing.</h3>
                </div>
                <a class="btn" href="/takoyaki-shop-infoman/login.php">Get the deal</a>
            </div>
            <div class="promo-card small">
                <div class="eyebrow">Chef favorite</div>
                <h3>Cheese Burst</h3>
                <p>Rich and gooey with a golden finish.</p>
            </div>
        </section>

        <section class="content-section about-section" id="about">
            <div class="about-card">
                <div class="eyebrow">Our story</div>
                <h2>Fresh bites, warm hospitality, and street-food joy.</h2>
                <p>From classic original flavors to bold specialty bites, Takoyaki Shop brings the spirit of Japanese street food to your everyday cravings. Every batch is prepared fresh, served hot, and made to share.</p>
            </div>
            <div class="about-highlight">
                <div class="badge">Made fresh</div>
                <div class="badge">Premium ingredients</div>
                <div class="badge">Fast and friendly service</div>
            </div>
        </section>
    </main>

    <footer class="landing-footer" id="contact">
        <div>
            <h3>Takoyaki Shop</h3>
            <p>Fresh takoyaki, grilled daily, served with a smile.</p>
        </div>
        <div>
            <h4>Contact</h4>
            <p>hello@takoyakishop.com</p>
            <p>+63 912 345 6789</p>
        </div>
        <div>
            <h4>Opening Hours</h4>
            <p>Mon - Sun</p>
            <p>10:00 AM - 8:00 PM</p>
        </div>
        <div>
            <h4>Follow us</h4>
            <p>Instagram · Facebook · TikTok</p>
        </div>
    </footer>
</div>
</body>
</html>