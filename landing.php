
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>GradeTrack | Digital Grading System</title>

<style>
* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
}

html {
    scroll-behavior: smooth;
}

body {
    font-family: "Segoe UI", Arial, sans-serif;
    background: #f7f9fc;
    color: #172033;
}

header {
    background: #fff;
    padding: 18px 8%;
    display: flex;
    align-items: center;
    justify-content: space-between;
    position: sticky;
    top: 0;
    z-index: 100;
    box-shadow: 0 2px 15px rgba(0,0,0,.05);
}

.logo {
    font-size: 25px;
    font-weight: 800;
    color: #14213d;
}

.logo span {
    color: #2879df;
}

nav {
    display: flex;
    align-items: center;
    gap: 28px;
}

nav a {
    color: #475569;
    text-decoration: none;
    font-size: 14px;
    font-weight: 600;
}

nav a:hover {
    color: #2879df;
}

nav .login-btn {
    background: #2879df;
    color: white;
    padding: 12px 23px;
    border-radius: 7px;
}

.hero {
    background: linear-gradient(120deg, #101d35, #174a91);
    color: white;
    padding: 105px 8%;
    display: grid;
    grid-template-columns: 1.3fr .7fr;
    align-items: center;
    gap: 50px;
}

.hero-tag {
    color: #93c5fd;
    font-size: 13px;
    font-weight: 700;
    letter-spacing: 2px;
    margin-bottom: 20px;
}

.hero h1 {
    font-size: clamp(36px, 5vw, 58px);
    line-height: 1.15;
    margin-bottom: 22px;
}

.hero h1 span {
    color: #60a5fa;
}

.hero p {
    color: #d6e2f2;
    line-height: 1.9;
    max-width: 600px;
    margin-bottom: 30px;
    font-size: 16px;
}

.btn {
    display: inline-block;
    text-decoration: none;
    padding: 14px 25px;
    border-radius: 7px;
    font-weight: 700;
    margin: 5px 8px 5px 0;
    transition: .2s;
}

.btn-primary {
    background: #3987ec;
    color: white;
}

.btn-primary:hover {
    background: #1764c5;
    transform: translateY(-2px);
}

.btn-outline {
    border: 1px solid #a9c8ed;
    color: white;
}

.hero-panel {
    background: rgba(255,255,255,.09);
    border: 1px solid rgba(255,255,255,.2);
    border-radius: 18px;
    padding: 30px;
    backdrop-filter: blur(10px);
}

.panel-title {
    font-size: 17px;
    margin-bottom: 25px;
}

.panel-item {
    background: rgba(255,255,255,.1);
    padding: 17px;
    margin-bottom: 13px;
    border-radius: 9px;
    display: flex;
    align-items: center;
    gap: 14px;
}

.panel-icon {
    width: 42px;
    height: 42px;
    border-radius: 10px;
    background: #2879df;
    display: grid;
    place-items: center;
    font-size: 20px;
}

.panel-item strong {
    display: block;
    font-size: 14px;
    margin-bottom: 5px;
}

.panel-item small {
    color: #cbdcf0;
}

section {
    padding: 75px 8%;
}

.section-heading {
    text-align: center;
    max-width: 650px;
    margin: 0 auto 45px;
}

.section-heading h2 {
    font-size: 32px;
    margin-bottom: 13px;
}

.section-heading p {
    color: #718096;
    line-height: 1.8;
}

.feature-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    gap: 22px;
}

.card {
    background: white;
    border: 1px solid #e7edf5;
    padding: 28px;
    border-radius: 12px;
    transition: .2s;
}

.card:hover {
    transform: translateY(-5px);
    box-shadow: 0 12px 25px rgba(0,0,0,.07);
}

.card-icon {
    width: 48px;
    height: 48px;
    background: #eaf2ff;
    border-radius: 11px;
    display: grid;
    place-items: center;
    font-size: 23px;
    margin-bottom: 20px;
}

.card h3 {
    font-size: 17px;
    margin-bottom: 12px;
}

.card p {
    color: #718096;
    font-size: 14px;
    line-height: 1.8;
}

.about {
    background: white;
    text-align: center;
}

.about-content {
    max-width: 800px;
    margin: auto;
}

.about-content p {
    color: #64748b;
    line-height: 1.9;
}

.cta {
    background: #142b4d;
    color: white;
    text-align: center;
}

.cta h2 {
    font-size: 30px;
    margin-bottom: 15px;
}

.cta p {
    color: #cbd5e1;
    margin-bottom: 25px;
    line-height: 1.7;
}

footer {
    background: #0c1729;
    color: #aab7ca;
    padding: 28px 8%;
    text-align: center;
    font-size: 13px;
    line-height: 1.8;
}

@media(max-width: 800px) {
    header {
        padding: 17px 5%;
        flex-wrap: wrap;
        gap: 17px;
    }

    nav {
        gap: 15px;
        flex-wrap: wrap;
    }

    .hero {
        grid-template-columns: 1fr;
        padding: 75px 6%;
    }

    section {
        padding: 60px 6%;
    }
}

@media(max-width: 480px) {
    nav {
        gap: 12px;
    }

    nav a {
        font-size: 12px;
    }

    .hero-panel {
        padding: 20px;
    }
}
</style>
</head>

<body>

<header>
    <div class="logo">Grade<span>Track.</span></div>

    <nav>
        <a href="landing.php">Home</a>
        <a href="#features">Features</a>
        <a href="#about">About</a>
        <a href="login.php" class="login-btn">Login</a>
    </nav>
</header>

<section class="hero">

    <div>
        <div class="hero-tag">DIGITAL ACADEMIC MANAGEMENT</div>

        <h1>
            Smarter Grading.<br>
            <span>Better Education.</span>
        </h1>

        <p>
            Welcome to GradeTrack, a digital grading system designed
            to simplify academic record management, improve data
            accuracy, and provide secure access to student results.
        </p>

        <a href="login.php" class="btn btn-primary">
            Access Your Account →
        </a>

        <a href="#features" class="btn btn-outline">
            Explore Features
        </a>
    </div>

    <div class="hero-panel">

        <div class="panel-title">A smarter academic experience</div>

        <div class="panel-item">
            <div class="panel-icon">✓</div>
            <div>
                <strong>Input Validation</strong>
                <small>Accurate grading information</small>
            </div>
        </div>

        <div class="panel-item">
            <div class="panel-icon">▤</div>
            <div>
                <strong>Academic Records</strong>
                <small>Organized student information</small>
            </div>
        </div>

        <div class="panel-item">
            <div class="panel-icon">♙</div>
            <div>
                <strong>Secure Access</strong>
                <small>Role-based account permissions</small>
            </div>
        </div>

    </div>

</section>

<section id="features">

    <div class="section-heading">
        <h2>Powerful System Features</h2>
        <p>
            Everything needed to manage academic grading
            efficiently in one centralized platform.
        </p>
    </div>

    <div class="feature-grid">

        <div class="card">
            <div class="card-icon">✓</div>
            <h3>Input Validation</h3>
            <p>
                Validates marks, required fields and student IDs
                before accepting grade submissions.
            </p>
        </div>

        <div class="card">
            <div class="card-icon">♙</div>
            <h3>Student Management</h3>
            <p>
                Enables authorized administrators to register
                and manage student records.
            </p>
        </div>

        <div class="card">
            <div class="card-icon">▤</div>
            <h3>Grade Management</h3>
            <p>
                Allows teachers to enter, review and manage
                student academic grades.
            </p>
        </div>

        <div class="card">
            <div class="card-icon">♙</div>
            <h3>Role-Based Access</h3>
            <p>
                Provides separate permissions for administrators,
                teachers and students.
            </p>
        </div>

        <div class="card">
            <div class="card-icon">⌕</div>
            <h3>Academic Reports</h3>
            <p>
                Supports organized access to academic reports
                and stored grading information.
            </p>
        </div>

        <div class="card">
            <div class="card-icon">◆</div>
            <h3>Audit Logs</h3>
            <p>
                Maintains records of grade-related activities
                to support accountability.
            </p>
        </div>

    </div>

</section>

<section class="about" id="about">

    <div class="about-content">
        <div class="section-heading">
            <h2>About GradeTrack</h2>
        </div>

        <p>
            GradeTrack is a web-based Digital Grading System
            developed to improve the management of academic
            records. It uses input validation to reduce errors,
            role-based access to control system permissions,
            and database integration to store and retrieve
            academic information efficiently.
        </p>
    </div>

</section>

<section class="cta">

    <h2>Ready to Access GradeTrack?</h2>

    <p>
        Log in securely to access the functions available
        to your account.
    </p>

    <a href="login.php" class="btn btn-primary">
        Login to System →
    </a>

</section>

<footer>
    <p>
        &copy; <?php echo date("Y"); ?>
        GradeTrack Digital Grading System.
        All rights reserved.
    </p>
    <p>Accuracy • Accountability • Academic Excellence</p>
</footer>

</body>
</html>
