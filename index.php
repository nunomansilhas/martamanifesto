<?php
// Configurações da base de dados
$host = 'localhost'; // ALTERAR PARA O SEU HOST REAL
$dbname = 'wtv-you-want'; // ALTERAR PARA O NOME DA SUA BASE DE DADOS REAL
$username = 'wtv-you-want'; // ALTERAR PARA SEU NOME DE UTILIZADOR REAL
$password = 'wtv-you-want'; // ALTERAR PARA SUA PASSWORD REAL

// Função para conectar à base de dados
function getDBConnection() {
    global $host, $dbname, $username, $password;
    try {
        $pdo = new PDO(
            "mysql:host=$host;dbname=$dbname;charset=utf8mb4",
            $username,
            $password,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false
            ]
        );
        return $pdo;
    } catch (PDOException $e) {
        error_log("Erro de conexão: " . $e->getMessage());
        return null;
    }
}

// Processar AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action'])) {
    header('Content-Type: application/json');
    
    $action = $_POST['ajax_action'];
    $platform = $_POST['platform'] ?? '';
    $user_ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    
    $pdo = getDBConnection();
    if (!$pdo) {
        echo json_encode(['error' => 'Erro de conexão com a base de dados']);
        exit;
    }
    
    try {
        // Criar tabelas se não existirem
        $pdo->exec("CREATE TABLE IF NOT EXISTS manifesto_feedback (
            id INT AUTO_INCREMENT PRIMARY KEY,
            likes_count INT DEFAULT 0,
            shares_facebook INT DEFAULT 0,
            shares_twitter INT DEFAULT 0,
            shares_linkedin INT DEFAULT 0,
            shares_whatsapp INT DEFAULT 0,
            shares_copy INT DEFAULT 0,
            last_updated TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )");
        
        $pdo->exec("CREATE TABLE IF NOT EXISTS manifesto_user_interactions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_ip VARCHAR(45) NOT NULL,
            has_liked BOOLEAN DEFAULT FALSE,
            shared_platforms TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY unique_ip (user_ip)
        )");
        
        // Inserir dados iniciais se não existir
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM manifesto_feedback");
        $stmt->execute();
        if ($stmt->fetchColumn() == 0) {
            $pdo->exec("INSERT INTO manifesto_feedback (likes_count, shares_facebook, shares_twitter, shares_linkedin, shares_whatsapp, shares_copy) VALUES (42, 8, 12, 3, 15, 7)");
        }
        
        if ($action === 'get_data') {
            // Obter dados do feedback
            $stmt = $pdo->prepare("SELECT * FROM manifesto_feedback LIMIT 1");
            $stmt->execute();
            $feedback_data = $stmt->fetch();
            
            // Obter dados do utilizador
            $stmt = $pdo->prepare("SELECT * FROM manifesto_user_interactions WHERE user_ip = ?");
            $stmt->execute([$user_ip]);
            $user_data = $stmt->fetch();
            
            if (!$user_data) {
                $user_data = ['has_liked' => false, 'shared_platforms' => '[]'];
            }
            
            echo json_encode([
                'success' => true,
                'data' => [
                    'likes' => (int)$feedback_data['likes_count'],
                    'shares' => [
                        'facebook' => (int)$feedback_data['shares_facebook'],
                        'twitter' => (int)$feedback_data['shares_twitter'],
                        'linkedin' => (int)$feedback_data['shares_linkedin'],
                        'whatsapp' => (int)$feedback_data['shares_whatsapp'],
                        'copy' => (int)$feedback_data['shares_copy']
                    ],
                    'user' => [
                        'hasLiked' => (bool)$user_data['has_liked'],
                        'hasShared' => json_decode($user_data['shared_platforms'] ?? '[]', true)
                    ]
                ]
            ]);
            
        } elseif ($action === 'like' || $action === 'unlike') {
            // Obter dados do utilizador
            $stmt = $pdo->prepare("SELECT * FROM manifesto_user_interactions WHERE user_ip = ?");
            $stmt->execute([$user_ip]);
            $user_data = $stmt->fetch();
            
            if (!$user_data) {
                $stmt = $pdo->prepare("INSERT INTO manifesto_user_interactions (user_ip, shared_platforms) VALUES (?, '[]')");
                $stmt->execute([$user_ip]);
                $user_data = ['has_liked' => false, 'shared_platforms' => '[]'];
            }
            
            if ($action === 'like' && !$user_data['has_liked']) {
                $stmt = $pdo->prepare("UPDATE manifesto_feedback SET likes_count = likes_count + 1");
                $stmt->execute();
                $stmt = $pdo->prepare("UPDATE manifesto_user_interactions SET has_liked = TRUE WHERE user_ip = ?");
                $stmt->execute([$user_ip]);
            } elseif ($action === 'unlike' && $user_data['has_liked']) {
                $stmt = $pdo->prepare("UPDATE manifesto_feedback SET likes_count = likes_count - 1");
                $stmt->execute();
                $stmt = $pdo->prepare("UPDATE manifesto_user_interactions SET has_liked = FALSE WHERE user_ip = ?");
                $stmt->execute([$user_ip]);
            }
            
            // Retornar dados atualizados
            $stmt = $pdo->prepare("SELECT * FROM manifesto_feedback LIMIT 1");
            $stmt->execute();
            $feedback_data = $stmt->fetch();
            
            $stmt = $pdo->prepare("SELECT * FROM manifesto_user_interactions WHERE user_ip = ?");
            $stmt->execute([$user_ip]);
            $user_data = $stmt->fetch();
            
            echo json_encode([
                'success' => true,
                'data' => [
                    'likes' => (int)$feedback_data['likes_count'],
                    'user' => ['hasLiked' => (bool)$user_data['has_liked']]
                ]
            ]);
            
        } elseif ($action === 'share') {
            $valid_platforms = ['facebook', 'twitter', 'linkedin', 'whatsapp', 'copy'];
            if (!in_array($platform, $valid_platforms)) {
                echo json_encode(['error' => 'Plataforma inválida']);
                exit;
            }
            
            // Atualizar contador
            $column = "shares_" . $platform;
            $stmt = $pdo->prepare("UPDATE manifesto_feedback SET $column = $column + 1");
            $stmt->execute();
            
            // Atualizar utilizador
            $stmt = $pdo->prepare("SELECT * FROM manifesto_user_interactions WHERE user_ip = ?");
            $stmt->execute([$user_ip]);
            $user_data = $stmt->fetch();
            
            if (!$user_data) {
                $stmt = $pdo->prepare("INSERT INTO manifesto_user_interactions (user_ip, shared_platforms) VALUES (?, ?)");
                $stmt->execute([$user_ip, json_encode([$platform])]);
            } else {
                $shared = json_decode($user_data['shared_platforms'] ?? '[]', true);
                if (!in_array($platform, $shared)) {
                    $shared[] = $platform;
                    $stmt = $pdo->prepare("UPDATE manifesto_user_interactions SET shared_platforms = ? WHERE user_ip = ?");
                    $stmt->execute([json_encode($shared), $user_ip]);
                }
            }
            
            // Obter dados atualizados para retornar
            $stmt = $pdo->prepare("SELECT * FROM manifesto_feedback LIMIT 1");
            $stmt->execute();
            $feedback_data = $stmt->fetch();
            
            echo json_encode([
                'success' => true, 
                'message' => 'Partilha registrada',
                'data' => [
                    'shares' => [
                        'facebook' => (int)$feedback_data['shares_facebook'],
                        'twitter' => (int)$feedback_data['shares_twitter'],
                        'linkedin' => (int)$feedback_data['shares_linkedin'],
                        'whatsapp' => (int)$feedback_data['shares_whatsapp'],
                        'copy' => (int)$feedback_data['shares_copy']
                    ]
                ]
            ]);
        }
        
    } catch (Exception $e) {
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manifesto - Marta Arcanjo</title>
    <link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'%3E%3Crect width='32' height='32' fill='%23d63031'/%3E%3Cpath d='M6 26 L6 6 L12 6 L16 18 L20 6 L26 6 L26 26 L22 26 L22 12 L18 26 L14 26 L10 12 L10 26 Z' fill='%23fff'/%3E%3C/svg%3E" type="image/svg+xml">
    <style>
        /* Importar fontes */
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap');
        @import url('https://fonts.cdnfonts.com/css/moela');
        @import url('https://fonts.cdnfonts.com/css/mevara');
        @import url('https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;700;900&family=Crimson+Text:wght@400;600&display=swap');
        
        /* Fallback para fontes */
        @font-face {
            font-family: 'Moela-fallback';
            src: local('Arial Black'), local('Helvetica Neue');
            font-weight: 900;
            font-style: normal;
        }
        @font-face {
            font-family: 'Mevara-fallback';
            src: local('Georgia'), local('Times New Roman');
            font-weight: normal;
            font-style: normal;
        }
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        /* ===== CURSOR PERSONALIZADO ===== */
        * {
            cursor: none;
        }
        
        .custom-cursor {
            position: fixed;
            width: 20px;
            height: 20px;
            border: 2px solid #d63031;
            border-radius: 50%;
            pointer-events: none;
            z-index: 10000;
            transition: all 0.1s ease;
            mix-blend-mode: difference;
        }
        
        .cursor-trail {
            position: fixed;
            width: 6px;
            height: 6px;
            background: rgba(214, 48, 49, 0.6);
            border-radius: 50%;
            pointer-events: none;
            z-index: 9999;
        }
        
        /* ===== LOADING ANIMATION ===== */
        .loading-screen {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: linear-gradient(135deg, #1a1a1a 0%, #2c2c2c 100%);
            z-index: 100000;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            opacity: 1;
            transition: opacity 1s ease-out;
        }
        
        .loading-screen.hidden {
            opacity: 0;
            pointer-events: none;
        }
        
        .typewriter-loading {
            font-family: 'Moela', monospace;
            font-size: 2em;
            color: #d63031;
            border-right: 3px solid #d63031;
            white-space: nowrap;
            overflow: hidden;
            animation: typeLoading 2s steps(20) forwards;
        }
        
        .loading-subtitle {
            font-family: 'Crimson Text', serif;
            color: #888;
            margin-top: 20px;
            font-size: 1.2em;
            opacity: 0;
            animation: fadeInUp 1s ease-out 1.5s forwards;
        }
        
        @keyframes typeLoading {
            0% { width: 0; }
            100% { width: 12ch; }
        }
        
        @keyframes fadeInUp {
            0% { opacity: 0; transform: translateY(20px); }
            100% { opacity: 1; transform: translateY(0); }
        }
        
        /* ===== PARTICLES SYSTEM ===== */
        .particles-container {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            pointer-events: none;
            z-index: 1;
            overflow: hidden;
        }
        
        .particle {
            position: absolute;
            background: rgba(139, 69, 19, 0.1);
            border-radius: 50%;
            pointer-events: none;
            animation: floatParticle 15s linear infinite;
        }
        
        @keyframes floatParticle {
            0% {
                transform: translateY(100vh) rotate(0deg);
                opacity: 0;
            }
            10% { opacity: 1; }
            90% { opacity: 1; }
            100% {
                transform: translateY(-100px) rotate(360deg);
                opacity: 0;
            }
        }
        body {
            background: 
                radial-gradient(circle at 20% 50%, rgba(214, 48, 49, 0.03) 0%, transparent 50%),
                radial-gradient(circle at 80% 20%, rgba(139, 69, 19, 0.02) 0%, transparent 50%),
                linear-gradient(135deg, #f4c2c2 0%, #f8d7da 100%);
            font-family: 'Crimson Text', serif;
            line-height: 1.4;
            color: #1a1a1a;
            padding: 20px;
            min-height: 100vh;
            transition: all 0.3s ease;
            overflow-x: hidden;
        }
        
        /* ===== SMOOTH SCROLL ===== */
        html {
            scroll-behavior: smooth;
        }
        
        /* ===== AMBIENT LIGHTING ===== */
        .ambient-light {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            pointer-events: none;
            z-index: 2;
            background: radial-gradient(600px circle at var(--mouse-x, 50%) var(--mouse-y, 50%), 
                rgba(214, 48, 49, 0.05) 0%, 
                transparent 40%);
            transition: background 0.2s ease;
        }
        
        body.dark-mode .ambient-light {
            background: radial-gradient(600px circle at var(--mouse-x, 50%) var(--mouse-y, 50%), 
                rgba(255, 107, 107, 0.08) 0%, 
                transparent 40%);
        }
        
        /* ===== PROGRESS BAR ===== */
        .reading-progress {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 4px;
            background: rgba(214, 48, 49, 0.1);
            z-index: 1000;
            backdrop-filter: blur(10px);
        }
        
        .progress-bar {
            height: 100%;
            background: linear-gradient(90deg, #d63031, #e74c3c);
            width: 0%;
            transition: width 0.1s ease;
            box-shadow: 0 0 10px rgba(214, 48, 49, 0.5);
        }
        
        /* ===== MODO NOTURNO ===== */
        .dark-mode-toggle {
            position: fixed;
            top: 20px;
            right: 20px;
            background: rgba(255, 255, 255, 0.9);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.3);
            border-radius: 50px;
            width: 50px;
            height: 50px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            transition: all 0.3s ease;
            z-index: 1001;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
        }
        
        .dark-mode-toggle:hover {
            transform: scale(1.1) rotate(10deg);
            box-shadow: 0 6px 20px rgba(0, 0, 0, 0.2);
        }
        
        /* Estilos para modo noturno */
        body.dark-mode {
            background-color: #1a1a1a;
            color: #e8e6e3;
        }
        
        body.dark-mode .newspaper-wrapper {
            background: #2c2c2c;
            border-color: #444;
            box-shadow: 0 20px 40px rgba(0,0,0,0.6);
        }
        
        body.dark-mode .newspaper-wrapper::before {
            background: 
                radial-gradient(circle at 20% 30%, rgba(139, 69, 19, 0.15) 0%, transparent 40%),
                radial-gradient(circle at 80% 70%, rgba(160, 82, 45, 0.1) 0%, transparent 30%),
                radial-gradient(circle at 40% 80%, rgba(101, 67, 33, 0.08) 0%, transparent 50%);
        }
        
        body.dark-mode .main-article {
            background: rgba(40, 40, 40, 0.9);
            border-color: #555;
        }
        
        body.dark-mode .main-article::before {
            background: linear-gradient(135deg, rgba(40,40,40,0.95) 0%, rgba(35,35,35,0.95) 100%);
        }
        
        body.dark-mode .article-title,
        body.dark-mode .manifesto-content {
            color: #e8e6e3;
        }
        
        body.dark-mode .article-category {
            color: #ff6b6b;
        }
        
        body.dark-mode .highlight-word {
            color: #ff6b6b;
        }
        
        body.dark-mode .highlight-phrase {
            color: #ff6b6b;
            background-color: rgba(255, 107, 107, 0.15);
        }
        
        body.dark-mode .highlight-box {
            background-color: #333;
            border-left-color: #ff6b6b;
        }
        
        body.dark-mode .highlight-strong {
            background: linear-gradient(120deg, rgba(255, 107, 107, 0.2) 0%, rgba(255, 107, 107, 0.1) 100%);
            border-color: #ff6b6b;
        }
        
        body.dark-mode .image-carousel {
            background: #333;
            border-color: #555;
        }
        
        body.dark-mode .carousel-nav {
            background: rgba(40, 40, 40, 0.9);
            color: #e8e6e3;
            border-color: rgba(255, 255, 255, 0.2);
        }
        
        body.dark-mode .carousel-nav:hover {
            background: rgba(255, 107, 107, 0.9);
        }
        
        body.dark-mode .carousel-indicator {
            background: rgba(255, 255, 255, 0.3);
            border-color: rgba(255, 255, 255, 0.5);
        }
        
        body.dark-mode .carousel-indicator.active {
            background: #ff6b6b;
        }
        
        body.dark-mode .feedback-btn {
            background: rgba(40, 40, 40, 0.9);
            border-color: rgba(255, 255, 255, 0.2);
            color: #e8e6e3;
        }
        
        body.dark-mode .feedback-btn:hover {
            background: rgba(50, 50, 50, 0.95);
        }
        
        body.dark-mode .manifesto-signature {
            background: rgba(40, 40, 40, 0.8);
            border-top-color: rgba(255, 107, 107, 0.3);
        }
        
        body.dark-mode .dark-mode-toggle {
            background: rgba(40, 40, 40, 0.9);
            border-color: rgba(255, 255, 255, 0.2);
            color: #e8e6e3;
        }
        /* Efeitos de papel antigo melhorados */
        .newspaper-wrapper {
            max-width: 1400px;
            margin: 0 auto;
            background: #f9f7f4;
            position: relative;
            box-shadow: 
                0 20px 40px rgba(0,0,0,0.3),
                0 0 0 1px rgba(139, 69, 19, 0.1),
                inset 0 0 50px rgba(139, 69, 19, 0.05);
            border: 1px solid #ddd;
            margin-bottom: 30px;
            transform: perspective(1000px) rotateX(1deg);
        }
        .newspaper-wrapper::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: 
                radial-gradient(circle at 20% 30%, rgba(139, 69, 19, 0.05) 0%, transparent 40%),
                radial-gradient(circle at 80% 70%, rgba(160, 82, 45, 0.03) 0%, transparent 30%),
                radial-gradient(circle at 40% 80%, rgba(101, 67, 33, 0.02) 0%, transparent 50%);
            pointer-events: none;
        }
        /* Layout principal do jornal */
        .newspaper-content {
            display: grid;
            grid-template-columns: 300px 1fr 300px;
            min-height: auto;
            position: relative;
            z-index: 5;
            align-items: start;
        }
        /* Colunas laterais desfocadas */
        .sidebar {
            padding: 30px 20px;
            filter: blur(0.8px);
            opacity: 0.5;
            font-size: 0.85em;
            line-height: 1.3;
        }
        .sidebar-article {
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 1px solid #ddd;
        }
        .sidebar-title {
            font-family: 'Playfair Display', serif;
            font-size: 1.1em;
            font-weight: 700;
            margin-bottom: 8px;
            color: #2c2c2c;
        }
        .sidebar-text {
            font-size: 0.8em;
            line-height: 1.4;
            color: #444;
            text-align: left;
            word-spacing: normal;
        }
        /* Artigo principal - manifesto */
        .main-article {
            padding: 40px 60px;
            background: rgba(255, 255, 255, 0.8);
            position: relative;
            z-index: 10;
            border-left: 1px solid #ddd;
            border-right: 1px solid #ddd;
        }
        .main-article::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: linear-gradient(135deg, rgba(255,255,255,0.9) 0%, rgba(249,247,244,0.9) 100%);
            z-index: -1;
        }
        .article-header {
            text-align: center;
            margin-bottom: 40px;
            padding-bottom: 20px;
            border-bottom: 2px solid #d63031;
        }
        .article-category {
            font-size: 1.5em;
            color: #d63031;
            font-weight: 600;
            letter-spacing: 1px;
            margin-bottom: 10px;
            text-transform: uppercase;
        }
        .article-title {
            font-family: 'Moela', 'Playfair Display', serif;
            font-size: 2.8em;
            font-weight: 900;
            line-height: 0.9;
            color: #1a1a1a;
            margin-bottom: 15px;
            letter-spacing: 0.5px;
            overflow: hidden;
            position: relative;
        }
        
        /* Animação de máquina de escrever no título */
        .typewriter-text {
            display: inline-block;
            border-right: 3px solid #d63031;
            animation: typewriter 3s steps(40) 1s both, blink 1s infinite, glitchTitle 0.3s ease-in-out 4s;
        }
        
        @keyframes typewriter {
            0% { width: 0; }
            100% { width: 100%; }
        }
        
        @keyframes blink {
            0%, 50% { border-color: #d63031; }
            51%, 100% { border-color: transparent; }
        }
        
        @keyframes glitchTitle {
            0%, 100% { transform: translate(0); }
            20% { transform: translate(-2px, 1px); }
            40% { transform: translate(-1px, -1px); }
            60% { transform: translate(1px, 1px); }
            80% { transform: translate(1px, -1px); }
        }
        
        /* ===== EFEITOS DE TINTA ===== */
        .ink-splash {
            position: absolute;
            pointer-events: none;
            z-index: 5;
            width: 40px;
            height: 40px;
            background: radial-gradient(circle, rgba(214, 48, 49, 0.8) 0%, transparent 70%);
            border-radius: 50%;
            animation: inkSpread 1.5s ease-out forwards;
        }
        
        @keyframes inkSpread {
            0% {
                transform: scale(0) rotate(0deg);
                opacity: 1;
            }
            50% {
                transform: scale(1.2) rotate(180deg);
                opacity: 0.6;
            }
            100% {
                transform: scale(2) rotate(360deg);
                opacity: 0;
            }
        }
        .article-subtitle {
            font-style: italic;
            color: #555;
            font-size: 1.1em;
            margin-bottom: 20px;
        }

        /* ===== CAROUSEL DE IMAGENS ===== */
        .image-carousel {
            position: relative;
            width: 100%;
            height: 300px;
            margin: 30px 0;
            border: 1px solid #ddd;
            background: #f5f5f5;
            overflow: hidden;
            border-radius: 8px;
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
            column-span: all; /* Para ocupar as duas colunas */
        }

        .carousel-container {
            position: relative;
            width: 100%;
            height: 100%;
        }

        .carousel-track {
            display: flex;
            width: 500%; /* 5 images × 100% */
            height: 100%;
            transition: transform 0.5s ease-in-out;
        }

        .carousel-slide {
            width: 20%; /* 100% ÷ 5 images */
            height: 100%;
            background-size: cover;
            background-position: center;
            background-repeat: no-repeat;
            position: relative;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        /* Imagens placeholder - substituir pelos seus URLs reais */
        .carousel-slide:nth-child(1) {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        }
        .carousel-slide:nth-child(2) {
            background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
        }
        .carousel-slide:nth-child(3) {
            background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
        }
        .carousel-slide:nth-child(4) {
            background: linear-gradient(135deg, #43e97b 0%, #38f9d7 100%);
        }
        .carousel-slide:nth-child(5) {
            background: linear-gradient(135deg, #fa709a 0%, #fee140 100%);
        }

        /* Placeholder text dentro das imagens */
        .carousel-slide::after {
            content: 'Imagem ' counter(slide-counter);
            counter-increment: slide-counter;
            color: white;
            font-family: 'Inter', sans-serif;
            font-size: 18px;
            font-weight: 600;
            text-shadow: 0 2px 4px rgba(0,0,0,0.5);
        }

        /* Reset counter */
        .carousel-track {
            counter-reset: slide-counter;
        }

        /* Botões de navegação */
        .carousel-nav {
            position: absolute;
            top: 50%;
            transform: translateY(-50%);
            background: rgba(255, 255, 255, 0.9);
            border: none;
            width: 40px;
            height: 40px;
            border-radius: 50%;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            color: #333;
            box-shadow: 0 2px 8px rgba(0,0,0,0.15);
            transition: all 0.3s ease;
            opacity: 0;
            visibility: hidden;
            z-index: 10;
        }

        .carousel-nav:hover {
            background: rgba(214, 48, 49, 0.9);
            color: white;
            transform: translateY(-50%) scale(1.1);
        }

        .carousel-nav.prev {
            left: 15px;
        }

        .carousel-nav.next {
            right: 15px;
        }

        /* Mostrar botões no hover */
        .image-carousel:hover .carousel-nav {
            opacity: 1;
            visibility: visible;
        }

        /* Indicadores de slides */
        .carousel-indicators {
            position: absolute;
            bottom: 15px;
            left: 50%;
            transform: translateX(-50%);
            display: flex;
            gap: 8px;
            z-index: 10;
        }

        .carousel-indicator {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.5);
            cursor: pointer;
            transition: all 0.3s ease;
            border: 2px solid rgba(255, 255, 255, 0.8);
        }

        .carousel-indicator.active {
            background: #d63031;
            border-color: white;
            transform: scale(1.2);
        }

        /* Efeito de overlay nos slides */
        .carousel-slide::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.1);
            transition: background 0.3s ease;
        }

        .image-carousel:hover .carousel-slide::before {
            background: rgba(0, 0, 0, 0.2);
        }

        /* Conteúdo do manifesto */
        .manifesto-content {
            columns: 2;
            column-gap: 50px;
            column-rule: 1px solid #ddd;
            text-align: left;
            font-family: 'Mevara', 'Mevara-fallback', 'Georgia', serif;
            font-size: 1.1rem;
            line-height: 1.7;
            color: #1a1a1a;
            hyphens: none;
            -webkit-hyphens: none;
            -moz-hyphens: none;
            -ms-hyphens: none;
            word-break: normal;
            overflow-wrap: normal;
            word-spacing: normal;
            letter-spacing: normal;
        }
        .verse {
            margin-bottom: 20px;
            break-inside: avoid;
            page-break-inside: avoid;
            orphans: 3;
            widows: 3;
        }
        /* Estilos de destaque melhorados */
        .highlight-word {
            font-family: 'Moela', 'Moela-fallback', 'Playfair Display', serif;
            color: #d63031;
            font-weight: 900;
            transition: all 0.3s ease;
            position: relative;
            cursor: pointer;
        }
        
        .highlight-word:hover {
            transform: scale(1.05);
            text-shadow: 2px 2px 4px rgba(214, 48, 49, 0.3);
            filter: drop-shadow(0 0 5px rgba(214, 48, 49, 0.4));
        }
        
        .highlight-word::after {
            content: '';
            position: absolute;
            bottom: -2px;
            left: 0;
            width: 0;
            height: 2px;
            background: linear-gradient(90deg, #d63031, transparent);
            transition: width 0.4s ease;
        }
        
        .highlight-word:hover::after {
            width: 100%;
        }
        
        .highlight-phrase {
            font-family: 'Moela', 'Moela-fallback', 'Playfair Display', serif;
            color: #d63031;
            font-weight: 700;
            background: linear-gradient(120deg, rgba(214, 48, 49, 0.1), rgba(214, 48, 49, 0.05));
            padding: 2px 4px;
            border-radius: 2px;
            transition: all 0.3s ease;
            position: relative;
            cursor: pointer;
        }
        
        .highlight-phrase:hover {
            background: linear-gradient(120deg, rgba(214, 48, 49, 0.2), rgba(214, 48, 49, 0.1));
            transform: scale(1.02);
            box-shadow: 0 2px 8px rgba(214, 48, 49, 0.2);
        }
        
        /* ===== SISTEMA DE CITAÇÕES ===== */
        .quote-popup {
            position: absolute;
            background: rgba(0, 0, 0, 0.9);
            color: white;
            padding: 15px 20px;
            border-radius: 12px;
            font-size: 14px;
            line-height: 1.4;
            max-width: 300px;
            z-index: 1000;
            opacity: 0;
            transform: translateY(10px);
            transition: all 0.3s ease;
            pointer-events: none;
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.1);
        }
        
        .quote-popup.show {
            opacity: 1;
            transform: translateY(0);
        }
        
        .quote-popup::before {
            content: '';
            position: absolute;
            top: -8px;
            left: 20px;
            width: 0;
            height: 0;
            border-left: 8px solid transparent;
            border-right: 8px solid transparent;
            border-bottom: 8px solid rgba(0, 0, 0, 0.9);
        }
        
        .quote-actions {
            display: flex;
            gap: 10px;
            margin-top: 10px;
        }
        
        .quote-action {
            background: rgba(214, 48, 49, 0.2);
            border: 1px solid rgba(214, 48, 49, 0.4);
            color: white;
            padding: 5px 10px;
            border-radius: 6px;
            font-size: 12px;
            cursor: pointer;
            transition: all 0.2s ease;
        }
        
        .quote-action:hover {
            background: rgba(214, 48, 49, 0.4);
            transform: scale(1.05);
        }
        
        /* ===== PARALLAX LAYERS ===== */
        .parallax-container {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            pointer-events: none;
            z-index: -1;
        }
        
        .parallax-layer {
            position: absolute;
            width: 120%;
            height: 120%;
            background-image: 
                radial-gradient(2px 2px at 20px 30px, rgba(139, 69, 19, 0.1), transparent),
                radial-gradient(2px 2px at 40px 70px, rgba(160, 82, 45, 0.08), transparent),
                radial-gradient(1px 1px at 90px 40px, rgba(101, 67, 33, 0.06), transparent);
            background-repeat: repeat;
            background-size: 100px 100px;
            animation: parallaxFloat 20s linear infinite;
        }
        
        @keyframes parallaxFloat {
            0% { transform: translateX(-10%) translateY(-10%); }
            100% { transform: translateX(-5%) translateY(-5%); }
        }
        .highlight-box {
            background-color: #f8f8f8;
            border-left: 4px solid #d63031;
            padding: 15px 20px;
            margin: 20px 0;
            font-weight: 600;
            column-span: all;
            border-radius: 0 5px 5px 0;
        }
        .highlight-strong {
            background: linear-gradient(120deg, rgba(214, 48, 49, 0.15) 0%, rgba(214, 48, 49, 0.05) 100%);
            border: 2px solid #d63031;
            padding: 10px 15px;
            border-radius: 5px;
            font-weight: 600;
            margin: 15px 0;
            column-span: all;
        }
        /* Final do artigo */
        .article-end {
            text-align: center;
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid #ddd;
            column-span: all;
            overflow: hidden; /* clearfix for float */
        }
        .article-end-mark {
            font-family: 'Moela', 'Playfair Display', serif;
            font-size: 2em;
            font-weight: 900;
            color: #d63031;
            letter-spacing: 3px;
        }
        /* Imagens simuladas nos artigos */
        .article-image {
            width: 100%;
            height: 80px;
            background: linear-gradient(45deg, #ddd 25%, #eee 25%, #eee 50%, #ddd 50%, #ddd 75%, #eee 75%);
            background-size: 8px 8px;
            margin-bottom: 8px;
            border: 1px solid #ccc;
            position: relative;
            filter: blur(0.8px);
            opacity: 0.5;
        }
        .article-image::before {
            content: '📸';
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            font-size: 1.5em;
            opacity: 0.6;
        }
        .large-image {
            height: 120px;
        }
        /* Boxes de destaque desfocados */
        .info-box {
            background: #f5f5f5;
            border: 2px solid #999;
            padding: 12px;
            margin: 15px 0;
            filter: blur(0.6px);
            opacity: 0.4;
        }
        .info-box h4 {
            font-size: 0.9em;
            font-weight: bold;
            margin-bottom: 6px;
            text-transform: uppercase;
        }
        /* Tabelas/listas desfocadas */
        .news-list {
            background: #fafafa;
            border: 1px solid #ddd;
            padding: 10px;
            margin: 15px 0;
            filter: blur(0.6px);
            opacity: 0.45;
            font-size: 0.75em;
        }
        .news-list h4 {
            font-size: 0.8em;
            font-weight: bold;
            margin-bottom: 8px;
            border-bottom: 1px solid #ccc;
            padding-bottom: 3px;
        }
        .news-list ul {
            list-style: none;
            margin: 0;
            padding: 0;
        }
        .news-list li {
            margin-bottom: 4px;
            padding-left: 8px;
            border-left: 2px solid #999;
            line-height: 1.2;
        }
        /* Classificados */
        .classifieds {
            background: #f8f8f8;
            border: 1px dashed #999;
            padding: 10px;
            margin: 15px 0;
            filter: blur(0.8px);
            opacity: 0.35;
            font-size: 0.7em;
        }
        .classifieds h4 {
            font-size: 0.8em;
            text-align: center;
            margin-bottom: 8px;
            font-weight: bold;
        }
        /* Cotações/preços */
        .market-box {
            background: #e8e8e8;
            border: 1px solid #999;
            padding: 8px;
            margin: 12px 0;
            filter: blur(0.6px);
            opacity: 0.4;
            font-size: 0.7em;
        }
        .weather-box {
            background: linear-gradient(135deg, #87CEEB 0%, #98D8E8 100%);
            border: 1px solid #5F9EA0;
            padding: 10px;
            margin: 15px 0;
            filter: blur(0.8px);
            opacity: 0.4;
            font-size: 0.75em;
            text-align: center;
            color: #2F4F4F;
        }
        /* Anúncios vintage nas laterais */
        .vintage-ad {
            background: #f0f0f0;
            border: 2px solid #666;
            padding: 15px;
            margin: 20px 0;
            text-align: center;
            filter: blur(0.8px);
            opacity: 0.4;
        }
        .vintage-ad h3 {
            font-size: 1em;
            margin-bottom: 8px;
            font-weight: bold;
        }
        /* Seção de economia/política desfocada */
        .news-section {
            filter: blur(0.6px);
            opacity: 0.45;
        }
        .news-section h3 {
            font-family: 'Playfair Display', serif;
            font-size: 1em;
            font-weight: 700;
            margin-bottom: 8px;
            color: #333;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border-bottom: 1px solid #999;
            padding-bottom: 3px;
        }
        
        /* ===== SISTEMA DE FEEDBACK ===== */
        .feedback-buttons {
            float: right;
            display: flex;
            gap: 12px;
            margin-top: 15px;
            margin-right: 15px;
            align-items: center;
            margin-bottom: 5px;
        }
        
        .feedback-btn {
            background: rgba(255, 255, 255, 0.8);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.3);
            border-radius: 12px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
            padding: 10px;
            color: #6b7280;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
        }
        
        .feedback-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
            background: rgba(255, 255, 255, 0.95);
            border-color: rgba(255, 255, 255, 0.5);
        }
        
        .feedback-btn:active {
            transform: translateY(0);
        }
        
        .like-btn {
            color: #6b7280;
        }
        
        .like-btn:hover {
            color: #ef4444;
            background: linear-gradient(135deg, rgba(239, 68, 68, 0.1), rgba(248, 113, 113, 0.05));
            border-color: rgba(239, 68, 68, 0.2);
        }
        
        .like-btn.liked {
            color: #ef4444;
            background: linear-gradient(135deg, rgba(239, 68, 68, 0.15), rgba(248, 113, 113, 0.08));
            border-color: rgba(239, 68, 68, 0.3);
        }
        
        .share-btn {
            color: #6b7280;
        }
        
        .share-btn:hover {
            color: #3b82f6;
            background: linear-gradient(135deg, rgba(59, 130, 246, 0.1), rgba(96, 165, 250, 0.05));
            border-color: rgba(59, 130, 246, 0.2);
        }
        
        .like-count, .share-count {
            position: absolute;
            top: -6px;
            right: -6px;
            color: white;
            border-radius: 10px;
            width: 20px;
            height: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 10px;
            font-weight: 600;
            min-width: 20px;
            font-family: 'Inter', sans-serif;
            border: 2px solid rgba(255, 255, 255, 0.9);
            transition: all 0.3s ease;
        }
        
        .like-count {
            background: linear-gradient(135deg, #ef4444, #dc2626);
            box-shadow: 0 2px 4px rgba(239, 68, 68, 0.3);
        }
        
        .share-count {
            background: linear-gradient(135deg, #3b82f6, #2563eb);
            box-shadow: 0 2px 4px rgba(59, 130, 246, 0.3);
        }
        
        /* ===== ANIMAÇÃO DE CONTADOR ===== */
        .count-animation {
            animation: countPop 0.6s cubic-bezier(0.68, -0.55, 0.265, 1.55);
        }
        
        @keyframes countPop {
            0% { transform: scale(1); }
            50% { transform: scale(1.4) rotate(10deg); }
            100% { transform: scale(1); }
        }
        
        /* ===== EFEITOS SONOROS VISUAIS ===== */
        .sound-wave {
            position: fixed;
            width: 20px;
            height: 20px;
            border: 2px solid rgba(214, 48, 49, 0.6);
            border-radius: 50%;
            pointer-events: none;
            z-index: 1000;
            animation: soundWave 1s ease-out forwards;
        }
        
        @keyframes soundWave {
            0% {
                transform: scale(1);
                opacity: 1;
            }
            100% {
                transform: scale(3);
                opacity: 0;
            }
        }
        
        .heart-animation {
            position: fixed;
            color: #ef4444;
            font-size: 18px;
            pointer-events: none;
            z-index: 1001;
            animation: heartFloat 2s ease-out forwards;
            filter: drop-shadow(0 2px 4px rgba(239, 68, 68, 0.3));
        }
        
        @keyframes heartFloat {
            0% {
                opacity: 1;
                transform: translateY(0) scale(1) rotate(0deg);
            }
            100% {
                opacity: 0;
                transform: translateY(-80px) scale(1.5) rotate(20deg);
            }
        }
        
        @keyframes likeAnimation {
            0%, 100% {
                transform: scale(1);
            }
            50% {
                transform: scale(1.05);
            }
        }
        
        /* Modal de partilha */
        .share-modal {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            backdrop-filter: blur(8px);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 10000;
            animation: fadeIn 0.3s ease-out;
        }
        
        .share-modal.active {
            display: flex;
        }
        
        .share-content {
            background: rgba(255, 255, 255, 0.98);
            backdrop-filter: blur(20px);
            border: 1px solid rgba(255, 255, 255, 0.3);
            border-radius: 20px;
            padding: 24px;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
            max-width: 320px;
            width: 90%;
            animation: slideInScale 0.3s cubic-bezier(0.34, 1.56, 0.64, 1);
        }
        
        .share-header {
            text-align: center;
            margin-bottom: 20px;
        }
        
        .share-title {
            font-size: 18px;
            font-weight: 600;
            color: #1f2937;
            margin-bottom: 8px;
            font-family: 'Inter', sans-serif;
        }
        
        .share-subtitle {
            font-size: 14px;
            color: #6b7280;
            font-family: 'Inter', sans-serif;
        }
        
        .share-options {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }
        
        @keyframes slideInScale {
            from {
                opacity: 0;
                transform: scale(0.9) translateY(20px);
            }
            to {
                opacity: 1;
                transform: scale(1) translateY(0);
            }
        }
        
        .share-option {
            display: flex;
            align-items: center;
            padding: 10px 12px;
            border-radius: 10px;
            text-decoration: none;
            color: #374151;
            transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
            font-size: 14px;
            font-weight: 500;
            font-family: 'Inter', sans-serif;
        }
        
        .share-option:hover {
            background: rgba(59, 130, 246, 0.08);
            color: #1f2937;
            transform: translateX(2px);
        }
        
        .share-option svg {
            margin-right: 10px;
        }
        
        /* Assinatura do Desenvolvedor - Fora da Caixa */
        .manifesto-signature {
            text-align: center;
            font-family: 'Crimson Text', serif;
            padding: 5px 150px;
            background: rgba(255, 255, 255, 0.6);
            backdrop-filter: blur(5px);
            border-top: 1px solid rgba(214, 48, 49, 0.2);
            margin-top: 0px;
        }
        
        .developer-credits {
            font-size: 12px;
            color: #666;
            font-style: italic;
            margin-bottom: 0;
        }
        
        .heart-link {
            text-decoration: none;
            font-size: 14px;
            transition: all 0.3s ease;
            display: inline-block;
        }
        
        .heart-link:hover {
            transform: scale(1.3);
            filter: brightness(1.2);
        }
        
        /* Responsivo melhorado */
        @media (max-width: 1200px) {
            .newspaper-content {
                grid-template-columns: 250px 1fr 250px;
            }
            
            .main-article {
                padding: 30px 35px;
            }
            
            .dark-mode-toggle {
                width: 45px;
                height: 45px;
                font-size: 16px;
            }
        }
        
        @media (max-width: 968px) {
            .newspaper-content {
                grid-template-columns: 1fr;
            }
            
            .sidebar {
                display: none;
            }
            
            .main-article {
                padding: 30px 25px;
                border: none;
            }
            
            .manifesto-content {
                columns: 1;
                column-gap: 0;
                column-rule: none;
                hyphens: none;
                -webkit-hyphens: none;
                text-align: left;
                word-spacing: normal;
            }
            
            .article-title {
                font-size: 2.2em;
            }
            
            .typewriter-text {
                animation: typewriter 2.5s steps(35) 0.5s both, blink 1s infinite;
            }

            .image-carousel {
                height: 200px;
                margin: 20px 0;
            }
            
            .feedback-buttons {
                gap: 10px;
                margin-right: 10px;
            }
            
            .feedback-btn {
                padding: 8px;
            }
            
            .share-content {
                max-width: 280px;
                padding: 20px;
            }
            
            .share-option {
                padding: 8px 10px;
                font-size: 13px;
            }
            
            .newspaper-wrapper {
                margin-bottom: 20px;
                transform: perspective(500px) rotateX(0.5deg);
            }
            
            .manifesto-signature {
                padding: 5px 20px;
                margin-top: 0px;
            }
            
            .developer-credits {
                font-size: 11px;
            }
            
            .heart-link {
                font-size: 13px;
            }
            
            .dark-mode-toggle {
                top: 15px;
                right: 15px;
                width: 40px;
                height: 40px;
                font-size: 14px;
            }
            
            .reading-progress {
                height: 3px;
            }
            
            /* Reduce heavy effects on mobile */
            .particles-container {
                display: none;
            }
            
            .parallax-layer {
                animation: none;
            }
            
            .custom-cursor,
            .cursor-trail {
                display: none;
            }
            
            .ambient-light {
                opacity: 0.5;
            }
            
            * {
                cursor: auto;
            }
        }
        
        /* Reduce motion for users who prefer it */
        @media (prefers-reduced-motion: reduce) {
            .parallax-layer,
            .particle,
            .paper-stain {
                animation: none;
            }
            
            .typewriter-text {
                animation: none;
                border-right: none;
            }
            
            .particles-container {
                display: none;
            }
        }
        /* Efeitos de papel antigo melhorados */
        .paper-stain {
            position: absolute;
            width: 50px;
            height: 50px;
            background: rgba(139, 69, 19, 0.08);
            border-radius: 50%;
            pointer-events: none;
            transition: all 0.3s ease;
        }
        
        body.dark-mode .paper-stain {
            background: rgba(139, 69, 19, 0.2);
        }
        
        .stain-1 { 
            top: 15%; 
            right: 8%; 
            width: 30px; 
            height: 40px; 
            transform: rotate(15deg);
            animation: float1 6s ease-in-out infinite;
        }
        .stain-2 { 
            bottom: 25%; 
            left: 5%; 
            width: 25px; 
            height: 25px;
            animation: float2 8s ease-in-out infinite;
        }
        .stain-3 { 
            top: 60%; 
            right: 12%; 
            width: 35px; 
            height: 20px; 
            transform: rotate(-10deg);
            animation: float3 7s ease-in-out infinite;
        }
        .stain-4 { 
            top: 35%; 
            left: 10%; 
            width: 20px; 
            height: 30px; 
            transform: rotate(25deg);
            animation: float1 9s ease-in-out infinite;
        }
        .stain-5 { 
            bottom: 15%; 
            right: 15%; 
            width: 28px; 
            height: 28px; 
            transform: rotate(-20deg);
            animation: float2 5s ease-in-out infinite;
        }
        .stain-6 { 
            top: 80%; 
            left: 20%; 
            width: 15px; 
            height: 35px; 
            transform: rotate(45deg);
            animation: float3 10s ease-in-out infinite;
        }
        .stain-7 { 
            top: 45%; 
            right: 5%; 
            width: 22px; 
            height: 18px; 
            transform: rotate(10deg);
            animation: float1 6.5s ease-in-out infinite;
        }
        
        @keyframes float1 {
            0%, 100% { transform: translateY(0px) rotate(15deg); }
            50% { transform: translateY(-3px) rotate(18deg); }
        }
        
        @keyframes float2 {
            0%, 100% { transform: translateY(0px) rotate(-10deg); }
            50% { transform: translateY(-2px) rotate(-8deg); }
        }
        
        @keyframes float3 {
            0%, 100% { transform: translateY(0px) rotate(25deg); }
            50% { transform: translateY(-1px) rotate(22deg); }
        }
    </style>
</head>
<body>
    <!-- Loading Screen -->
    <div class="loading-screen" id="loadingScreen">
        <div class="typewriter-loading">Carregando...</div>
        <div class="loading-subtitle">Preparando o manifesto</div>
    </div>
    
    <!-- Custom Cursor -->
    <div class="custom-cursor" id="customCursor"></div>
    
    <!-- Particles Container -->
    <div class="particles-container" id="particlesContainer"></div>
    
    <!-- Parallax Background -->
    <div class="parallax-container">
        <div class="parallax-layer"></div>
    </div>
    
    <!-- Ambient Light Effect -->
    <div class="ambient-light" id="ambientLight"></div>
    
    <!-- Progress Bar -->
    <div class="reading-progress">
        <div class="progress-bar" id="progressBar"></div>
    </div>
    
    <!-- Dark Mode Toggle -->
    <button class="dark-mode-toggle" id="darkModeToggle" aria-label="Alternar modo noturno">
        🌙
    </button>
    
    <!-- Quote Popup -->
    <div class="quote-popup" id="quotePopup">
        <div class="quote-text"></div>
        <div class="quote-actions">
            <button class="quote-action" data-action="share">📤 Partilhar</button>
            <button class="quote-action" data-action="copy">📋 Copiar</button>
        </div>
    </div>
    
    <div class="newspaper-wrapper">
        <!-- Manchas de papel antigo -->
        <div class="paper-stain stain-1"></div>
        <div class="paper-stain stain-2"></div>
        <div class="paper-stain stain-3"></div>
        <div class="paper-stain stain-4"></div>
        <div class="paper-stain stain-5"></div>
        <div class="paper-stain stain-6"></div>
        <div class="paper-stain stain-7"></div>
        <!-- Conteúdo principal -->
        <div class="newspaper-content">
            <!-- Sidebar esquerda -->
            <aside class="sidebar">
                <div class="news-section">
                    <h3>Política</h3>
                    <article class="sidebar-article">
                        <div class="article-image"></div>
                        <h4 class="sidebar-title">Ministro da Cultura apresenta nova estratégia</h4>
                        <p class="sidebar-text">O governo anunciou ontem um plano quinquenal para o sector cultural, prevendo investimentos de 200 milhões de euros. As medidas incluem apoios diretos aos artistas e criação de novos espaços culturais. O ministro sublinhou a importância de democratizar o acesso à cultura...</p>
                    </article>
                    <article class="sidebar-article">
                        <h4 class="sidebar-title">Parlamento debate financiamento das artes</h4>
                        <p class="sidebar-text">Deputados de vários partidos reuniram-se para discutir o orçamento cultural para 2026. A proposta prevê um aumento de 15% face ao ano anterior. Os partidos da oposição criticam a insuficiência das verbas...</p>
                    </article>
                </div>
                <div class="vintage-ad">
                    <h3>GALERIA MODERNA</h3>
                    <p>Exposições de Arte<br>Contemporânea<br><strong>Rua das Flores, 42</strong><br>☎ 213 555 123</p>
                </div>
                <div class="info-box">
                    <h4>Agenda Cultural</h4>
                    <p>• Teatro Nacional - "Hamlet"<br>• CCB - Expo Miró<br>• Museu Gulbenkian - Arte Islâmica</p>
                </div>
                <div class="news-section">
                    <h3>Economia</h3>
                    <article class="sidebar-article">
                        <div class="article-image large-image"></div>
                        <h4 class="sidebar-title">Mercado da arte em crescimento</h4>
                        <p class="sidebar-text">Estudo revela aumento de 23% nas vendas de obras de arte nacionais. Especialistas apontam para maior consciencialização cultural da população e investimento de colecionadores privados...</p>
                    </article>
                    <article class="sidebar-article">
                        <h4 class="sidebar-title">Leilão bate recordes em Lisboa</h4>
                        <p class="sidebar-text">Casa de leilões vendeu 120 obras por valor total de 2,3 milhões de euros. Pintura de Amadeo de Souza-Cardoso atingiu o valor mais alto...</p>
                    </article>
                </div>
                <div class="market-box">
                    <h4>Cotações Arte</h4>
                    <p>• Pintura séc. XIX ↗ +12%<br>• Escultura moderna ↘ -3%<br>• Arte digital ↗ +45%</p>
                </div>
                <div class="news-section">
                    <h3>Local</h3>
                    <article class="sidebar-article">
                        <h4 class="sidebar-title">Nova biblioteca inaugurada no Seixal</h4>
                        <p class="sidebar-text">Espaço de 3000m² inclui auditório e salas de exposições. Autarca destaca investimento na educação e cultura...</p>
                    </article>
                </div>
                <div class="classifieds">
                    <h4>CLASSIFICADOS</h4>
                    <p><strong>VENDE-SE</strong><br>
                    • Quadros antigos<br>
                    • Piano vertical<br>
                    • Livros arte séc. XX<br><br>
                    <strong>PROCURA-SE</strong><br>
                    • Professor pintura<br>
                    • Molduras antigas</p>
                </div>
                <div class="news-section">
                    <h3>Opinião</h3>
                    <article class="sidebar-article">
                        <h4 class="sidebar-title">Arte como resistência cultural</h4>
                        <p class="sidebar-text">Coluna semanal de João Mendes. A arte sempre foi uma forma de resistência às estruturas dominantes. Hoje, mais do que nunca, precisamos de artistas que desafiem o status quo...</p>
                    </article>
                </div>
                <div class="vintage-ad">
                    <h3>ATELIER CRIATIVO</h3>
                    <p>Aulas de Desenho<br>e Pintura<br><strong>Grupos pequenos</strong><br>R. da Esperança, 89<br>☎ 213 444 567</p>
                </div>
                <div class="news-section">
                    <h3>Educação</h3>
                    <article class="sidebar-article">
                        <h4 class="sidebar-title">Ensino artístico em debate</h4>
                        <p class="sidebar-text">Pedagogos discutem reformas no ensino das artes. Proposta inclui mais horas de educação visual e musical no currículo obrigatório...</p>
                    </article>
                </div>
                <div class="info-box">
                    <h4>CONCURSOS</h4>
                    <p>• Prémio Jovem Pintor 2025<br>• Concurso Fotografia Digital<br>• Bolsas Criação Artística</p>
                </div>
                <div class="news-section">
                    <h3>Tecnologia</h3>
                    <article class="sidebar-article">
                        <h4 class="sidebar-title">Arte digital ganha espaço</h4>
                        <p class="sidebar-text">NFTs e criações digitais movimentam mercado português. Artistas nacionais exploram novas linguagens tecnológicas...</p>
                    </article>
                </div>
                <div class="vintage-ad">
                    <h3>MUSEU DO AZULEJO</h3>
                    <p>Exposição Permanente<br>• Arte Cerâmica Portuguesa<br>• Entrada Gratuita Domingos<br><strong>Madre de Deus</strong><br>☎ 218 100 340</p>
                </div>
                <div class="news-section">
                    <h3>Patrimônio</h3>
                    <article class="sidebar-article">
                        <div class="article-image"></div>
                        <h4 class="sidebar-title">Convento recupera frescos barrocos</h4>
                        <p class="sidebar-text">Obras de restauro revelam pinturas murais do século XVII. Técnicas inovadoras permitem recuperar cores originais após três séculos...</p>
                    </article>
                </div>
            </aside>
            <!-- Artigo principal - Manifesto -->
            <main class="main-article">
                <header class="article-header">
                    <div class="article-category">Manifesto</div>
                    <h1 class="article-title">
                        <span class="typewriter-text">"Talvez esteja na hora, de um Novo Manifesto"</span>
                    </h1>
                </header>
                
                <div class="manifesto-content">
                    <div class="verse">
                        A Arte é do povo, e ao povo deve retornar.<br>
                        E quem vier contradizer esta premissa,<br>
                        É porque desconhece, profundamente<br>
                        A história e a origem da Arte.
                    </div>
                    <div class="verse">
                        A arte nasce no povo - como forma de representação da vida.<br>
                        Mas certos senhores,<br>
                        Sequestraram o seu propósito.<br>
                        Tornando-a um ornamento do ego —<br>
                        símbolo de conquistas, de fé imposta,<br>
                        de uma distinção de classes, pretensiosa e obsoleta.
                    </div>
                    <div class="verse">
                        A Arte não poderá continuar a ser um comprovativo de estatuto.<br>
                        A Arte deve comunicar,<br>
                        E não encerrar-se em discursos obtusos<br>
                        E aparatos luxuosos.
                    </div>
                    <div class="verse">
                        A Arte pode prestar um serviço,<br>
                        Mas nunca ser alvo de serventia,<br>
                        Nem o artista, prestar qualquer ato de vassalagem.
                    </div>
                    <div class="verse">
                        A Arte não deve ser apenas, produto de uma contemplação,<br>
                        Mas objeto de uma reflexão.<br>
                        Nem a Arte nem o Artista, devem ser fruto do ego.<br>
                        Nem a Arte Nem o Artista,<br>
                        Devem pertencer a qualquer elite.
                    </div>
                    <div class="verse">
                        A Arte deve estar ao serviço do Ensino,<br>
                        Mas um Artista não deve nunca, formar-se em Arte.<br>
                        As instituições,
                    </div>
                    <div class="verse">
                        Dotam o Artista de um individualismo crónico,<br>
                        Semeiam a sentença de viver na espera,<br>
                        Nessa ânsia de que um dia,<br>
                        Alguém valide os seus aforismos de génio,<br>
                        Que conquiste uma bolsa, ou talvez um prémio!
                    </div>
                    <div class="verse">
                        Arrisco-me a dizer,<br>
                        Que a Arte, corre o risco,<br>
                        De perder a sua relevância.<br>
                        Porque lhe é dada demasiada importância…<br>
                        O seu propósito é extinto.<br>
                        E o Artista mendiga por um pelinto.
                    </div>
                    <div class="verse">
                        A Arte deveria igualar-se a um bem essencial,<br>
                        Pois esse é o apanágio da sua essência.<br>
                        O Artista deve moldar consciências,<br>
                        Semear um qualquer estado de Alerta,<br>
                        A Arte é uma denúncia em estado físico<br>
                        A Arte, é uma Ciência.
                    </div>
                    <div class="verse">
                        Produzir Arte, é dizer o que não pode ser dito<br>
                        O produto, produzido deve ser proclamado<br>
                        Sem espetáculo mediático.<br>
                        É na mediação que tantas vezes,<br>
                        O artista peca por usar palavras ao acaso…<br>
                        Que por caras, o tornam eloquente, culto e exclusivo.
                    </div>
                    <div class="verse">
                        A Arte deve ser um lugar de pertença e não de pretensão.
                    </div>
                    <div class="verse">
                        A Arte não deve ser objeto de especulação.<br>
                        Quando o lucro se sobrepõe à intenção,<br>
                        Onde se inflaciona, sob o pretexto da exclusividade e da autoria,<br>
                        O artista penhora, sem retorno,<br>
                        O fruto da sua alegoria.
                    </div>
                    <div class="verse">
                        A arte é usufruto, um instrumento que se revela incoerente,<br>
                        Quando em jeito de dádiva, o público é ausente…<br>
                        A Arte é subterfugio da mente.<br>
                        Um desabafo da consciência,<br>
                        Que só se consagra com a audiência.
                    </div>
                    <div class="verse">
                        Ser artista, é viver eternamente,<br>
                        Na procura constante, pela aclamada validação.<br>
                        E o artista desiste, pois sobrevive e não existe.<br>
                        Enquanto a arte for um lobby, ser artista não passará de um hobbie.
                    </div>
                    <div class="verse">
                        É nos trabalhos precários que o artista encontra subsistência.<br>
                        O artista, é intemporalmente, uma ausência.<br>
                        Esse trabalho é nobre, mas não há qualquer nobreza<br>
                        Que no apanágio das suas competências, um artista, sirva à mesa.
                    </div>
                    <div class="verse">
                        Um artista deve ser livre.<br>
                        Mas a liberdade percorre hoje, caminhos de incerteza.<br>
                        E um artista que teme ferir suscetibilidades,<br>
                        Condena a sua aptidão.<br>
                        Disfarça a sua vulnerabilidade,<br>
                        Pois, Teme o ódio e a avareza.
                    </div>
                    <div class="verse">
                        A Arte é o espelho da liberdade de expressão.<br>
                        Mas o artista, é intolerante a quem se oponha à sua visão.
                    </div>
                    <div class="verse">
                        Se a Arte não viraliza,<br>
                        O artista é diletante.<br>
                        O algoritmo dissidente,<br>
                        E Enquanto a Minha Arte não viraliza<br>
                        Algo na minha moral se amortiza.
                    </div>
                    <div class="verse">
                        E sinto a tentação,<br>
                        De agir por oposição<br>
                        À condição necessária,<br>
                        Para diluir a classe estatutária.<br>
                        Já ouvi com altivez,<br>
                        E não foi uma, nem duas, nem três!<br>
                        Que Tudo, o que me resta são valores…<br>
                        Além de Amores e outros dissabores.
                    </div>
                    <div class="verse">
                        A Arte é em si, um ato de insistência…<br>
                        E se esta for a minha última aparição,<br>
                        Que eu consiga falar com verdade:<br>
                        Da dor. Do sonho. E da ilusão.<br>
                        Ser Artista, é entrar numa queda constante<br>
                        Do propósito à função.
                    </div>
                    <div class="verse">
                        Ninguém vos conta como é vil e atroz,<br>
                        O caminho incerto e devoluto<br>
                        Do Artista que em vez de palco, quer ter voz.<br>
                        Eis a razão do meu Luto.
                    </div>
                    <div class="verse">
                        Nem na Arte, nem na Vida, o artista se pode consagrar.<br>
                        A Arte deve privilegiar a partilha de conhecimento,<br>
                        A Arte deve ser plural, coletiva e fruto de uma colaboração.
                    </div>
                    <div class="verse">
                        Mas A Minha Arte, é acima de tudo,<br>
                        Um surto de Inconformismo, Dor e Emoção.
                    </div>
                    
                    <!-- Carousel de Imagens -->
                    <div class="image-carousel">
                        <div class="carousel-container">
                            <div class="carousel-track" id="carouselTrack">
                                <div class="carousel-slide"></div>
                                <div class="carousel-slide"></div>
                                <div class="carousel-slide"></div>
                                <div class="carousel-slide"></div>
                                <div class="carousel-slide"></div>
                            </div>
                            
                            <button class="carousel-nav prev" id="prevBtn">‹</button>
                            <button class="carousel-nav next" id="nextBtn">›</button>
                            
                            <div class="carousel-indicators">
                                <div class="carousel-indicator active" data-slide="0"></div>
                                <div class="carousel-indicator" data-slide="1"></div>
                                <div class="carousel-indicator" data-slide="2"></div>
                                <div class="carousel-indicator" data-slide="3"></div>
                                <div class="carousel-indicator" data-slide="4"></div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="article-end">
                        <div class="article-end-mark">FIM!</div>
                        
                        <!-- Botões de Feedback -->
                        <div class="feedback-buttons">
                            <button class="feedback-btn like-btn" id="likeBtn">
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor">
                                    <path d="M1 21h4V9H1v12zm22-11c0-1.1-.9-2-2-2h-6.31l.95-4.57.03-.32c0-.41-.17-.79-.44-1.06L14.17 1 7.59 7.59C7.22 7.95 7 8.45 7 9v10c0 1.1.9 2 2 2h9c.83 0 1.54-.5 1.84-1.22l3.02-7.05c.09-.23.14-.47.14-.73v-2z"/>
                                </svg>
                                <span class="like-count" id="likeCount">0</span>
                            </button>
                            <button class="feedback-btn share-btn" id="shareBtn">
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor">
                                    <path d="M18 16.08c-.76 0-1.44.3-1.96.77L8.91 12.7c.05-.23.09-.46.09-.7s-.04-.47-.09-.7l7.05-4.11c.54.5 1.25.81 2.04.81 1.66 0 3-1.34 3-3s-1.34-3-3-3-3 1.34-3 3c0 .24.04.47.09.7L8.04 9.81C7.5 9.31 6.79 9 6 9c-1.66 0-3 1.34-3 3s1.34 3 3 3c.79 0 1.5-.31 2.04-.81l7.12 4.16c-.05.21-.08.43-.08.65 0 1.61 1.31 2.92 2.92 2.92 1.61 0 2.92-1.31 2.92-2.92s-1.31-2.92-2.92-2.92z"/>
                                </svg>
                                <span class="share-count" id="shareCount">0</span>
                            </button>
                        </div>
                    </div>
                </div>
            </main>
            <!-- Sidebar direita -->
            <aside class="sidebar">
                <div class="weather-box">
                    <h4>METEOROLOGIA</h4>
                    <p>Lisboa: 24°C ☀️<br>Porto: 19°C ⛅<br>Faro: 28°C ☀️</p>
                </div>
                <div class="vintage-ad">
                    <h3>ESCOLA DE BELAS ARTES</h3>
                    <p>Matrículas Abertas<br>Cursos de Pintura<br>e Escultura<br><strong>Tel: 213 456 789</strong><br>www.escola-artes.pt</p>
                </div>
                <div class="news-section">
                    <h3>Cultura</h3>
                    <article class="sidebar-article">
                        <div class="article-image"></div>
                        <h4 class="sidebar-title">Festival de Arte Contemporânea regressa a Lisboa</h4>
                        <p class="sidebar-text">A quinta edição do evento promete reunir mais de 200 artistas nacionais e internacionais. As exposições decorrem entre 20 e 30 de julho em vários espaços da capital. Destaque para a instalação interativa no Cais do Sodré...</p>
                    </article>
                    <article class="sidebar-article">
                        <div class="article-image"></div>
                        <h4 class="sidebar-title">Museu Nacional reabre após restauro</h4>
                        <p class="sidebar-text">Dois anos depois, o espaço volta a receber visitantes com uma nova exposição permanente dedicada à arte portuguesa do século XX. O investimento de 5 milhões de euros renovou completamente as instalações...</p>
                    </article>
                </div>
                <div class="news-list">
                    <h4>BREVES CULTURAIS</h4>
                    <ul>
                        <li>Concerto de fado no Casino Estoril</li>
                        <li>Exposição de fotografia no Metro</li>
                        <li>Workshop de cerâmica em Óbidos</li>
                        <li>Festival de cinema em Setúbal</li>
                        <li>Feira do livro antigo na Póvoa</li>
                    </ul>
                </div>
                <div class="vintage-ad">
                    <h3>ANTIQUÁRIO SÃO BENTO</h3>
                    <p>Compra e Venda<br>• Pinturas Antigas<br>• Mobiliário Séc. XVIII<br>• Porcelanas<br><strong>R. São Bento, 234</strong></p>
                </div>
                <div class="news-section">
                    <h3>Internacional</h3>
                    <article class="sidebar-article">
                        <div class="article-image large-image"></div>
                        <h4 class="sidebar-title">Bienal de Veneza bate recordes</h4>
                        <p class="sidebar-text">A edição deste ano da prestigiada mostra de arte contemporânea recebeu mais de 600 mil visitantes nos primeiros três meses. O pavilhão português apresenta obra sobre identidade nacional...</p>
                    </article>
                    <article class="sidebar-article">
                        <h4 class="sidebar-title">Louvre recupera obra roubada</h4>
                        <p class="sidebar-text">Pintura do séc. XVII foi encontrada em leilão privado. Interpol confirma autenticidade da obra desaparecida há 15 anos...</p>
                    </article>
                </div>
                <div class="info-box">
                    <h4>ESPETÁCULOS</h4>
                    <p><strong>Teatro</strong><br>• D. Maria II - "Auto da Barca"<br>• Trindade - Musical Broadway<br><br><strong>Concertos</strong><br>• Coliseu - Orquestra Sinfónica<br>• Aula Magna - Jazz Festival</p>
                </div>
                <div class="vintage-ad">
                    <h3>LIVRARIA CULTURAL</h3>
                    <p>Livros de Arte<br>e Filosofia<br><strong>Promoção 20% desconto</strong><br>Largo do Chiado, 15<br>Aberto até 22h</p>
                </div>
                <div class="news-section">
                    <h3>Desporto Cultural</h3>
                    <article class="sidebar-article">
                        <h4 class="sidebar-title">Olimpíadas de Arte Juvenil</h4>
                        <p class="sidebar-text">Mais de 500 jovens participam na competição nacional de artes plásticas. Final decorre no próximo fim de semana em Coimbra...</p>
                    </article>
                </div>
                <div class="classifieds">
                    <h4>OPORTUNIDADES</h4>
                    <p><strong>EMPREGO</strong><br>
                    • Curador museu<br>
                    • Professor arte<br>
                    • Designer gráfico<br><br>
                    <strong>FORMAÇÃO</strong><br>
                    • Curso restauro<br>
                    • Workshop fotografia</p>
                </div>
                <div class="market-box">
                    <h4>Índice Cultural</h4>
                    <p>• Visitantes museus: +8%<br>• Vendas livros: +12%<br>• Espetáculos: +5%<br>• Exposições: +15%</p>
                </div>
                <div class="news-section">
                    <h3>Crítica</h3>
                    <article class="sidebar-article">
                        <h4 class="sidebar-title">O futuro dos museus públicos</h4>
                        <p class="sidebar-text">Análise de Maria Santos sobre a democratização cultural. Como tornar os espaços museológicos mais acessíveis às comunidades locais...</p>
                    </article>
                </div>
                <div class="vintage-ad">
                    <h3>MOLDURAS ARTESANAIS</h3>
                    <p>Oficina Tradicional<br>• Restauro de Quadros<br>• Molduras por Medida<br><strong>Travessa do Carmo, 8</strong><br>☎ 218 765 432</p>
                </div>
            </aside>
        </div>
        
        <!-- Assinatura do Desenvolvedor - Fora da Caixa do Manifesto -->
        <div class="manifesto-signature">
            <p class="developer-credits">
                Desenvolvido com 
                <a href="https://nuno.mansilhas.pt/" target="_blank" rel="noopener noreferrer" class="heart-link" aria-label="Desenvolvido por Nuno Mansilhas">❤️</a> 
                em Portugal
            </p>
        </div>
    </div>
    <!-- Modal de Partilha -->
    <div class="share-modal" id="shareModal">
        <div class="share-content">
            <div class="share-header">
                <h3 class="share-title">Partilhar Manifesto</h3>
                <p class="share-subtitle">Escolha onde quer partilhar</p>
            </div>
            <div class="share-options">
                <a href="javascript:void(0)" class="share-option" data-platform="facebook">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="#1877f2">
                        <path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/>
                    </svg>
                    Facebook
                </a>
                <a href="javascript:void(0)" class="share-option" data-platform="twitter">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="#1da1f2">
                        <path d="M23.953 4.57a10 10 0 01-2.825.775 4.958 4.958 0 002.163-2.723c-.951.555-2.005.959-3.127 1.184a4.92 4.92 0 00-8.384 4.482C7.69 8.095 4.067 6.13 1.64 3.162a4.822 4.822 0 00-.666 2.475c0 1.71.87 3.213 2.188 4.096a4.904 4.904 0 01-2.228-.616v.06a4.923 4.923 0 003.946 4.827 4.996 4.996 0 01-2.212.085 4.936 4.936 0 004.604 3.417 9.867 9.867 0 01-6.102 2.105c-.39 0-.779-.023-1.17-.067a13.995 13.995 0 007.557 2.209c9.053 0 13.998-7.496 13.998-13.985 0-.21 0-.42-.015-.63A9.935 9.935 0 0024 4.59z"/>
                    </svg>
                    Twitter
                </a>
                <a href="javascript:void(0)" class="share-option" data-platform="linkedin">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="#0077b5">
                        <path d="M20.447 20.452h-3.554v-5.569c0-1.328-.027-3.037-1.852-3.037-1.853 0-2.136 1.445-2.136 2.939v5.667H9.351V9h3.414v1.561h.046c.477-.9 1.637-1.85 3.37-1.85 3.601 0 4.267 2.37 4.267 5.455v6.286zM5.337 7.433c-1.144 0-2.063-.926-2.063-2.065 0-1.138.92-2.063 2.063-2.063 1.14 0 2.064.925 2.064 2.063 0 1.139-.925 2.065-2.064 2.065zm1.782 13.019H3.555V9h3.564v11.452zM22.225 0H1.771C.792 0 0 .774 0 1.729v20.542C0 23.227.792 24 1.771 24h20.451C23.2 24 24 23.227 24 22.271V1.729C24 .774 23.2 0 22.222 0h.003z"/>
                    </svg>
                    LinkedIn
                </a>
                <a href="javascript:void(0)" class="share-option" data-platform="whatsapp">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="#25d366">
                        <path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893A11.821 11.821 0 0020.465 3.488"/>
                    </svg>
                    WhatsApp
                </a>
                <a href="javascript:void(0)" class="share-option" data-platform="copy">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="#666">
                        <path d="M16 1H4c-1.1 0-2 .9-2 2v14h2V3h12V1zm3 4H8c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h11c1.1 0 2-.9 2-2V7c0-1.1-.9-2-2-2zm0 16H8V7h11v14z"/>
                    </svg>
                    Copiar Link
                </a>
            </div>
        </div>
    </div>
    <script>
        // ===== SISTEMA DE DESTAQUES =====
        const ATIVAR_DESTAQUES = 0; // 1 para ativar, 0 para desativar
        
        const destaques = {
            palavras: [
                "Arte",
                "Artista", 
                "povo",
                "elite",
                "ego"
            ],
            
            frases: [
                "A Arte é do povo, e ao povo deve retornar",
                "sequestraram o seu propósito",
                "A Arte é uma denúncia em estado físico",
                "Enquanto a arte for um lobby, ser artista não passará de um hobbie",
                "A Arte é o espelho da liberdade de expressão"
            ],
            
            caixas: [
                "A Arte não poderá continuar a ser um comprovativo de estatuto.",
                "A Arte deveria igualar-se a um bem essencial, pois esse é o apanágio da sua essência.",
                "A Arte deve ser um lugar de pertença e não de pretensão.",
                "A Arte é em si, um ato de insistência…"
            ],
            
            fortes: [
                "um surto de Inconformismo, Dor e Emoção"
            ]
        };

        // ===== LOADING SYSTEM =====
        class LoadingSystem {
            constructor() {
                this.loadingScreen = document.getElementById('loadingScreen');
                this.init();
            }

            init() {
                // Simulate loading time
                setTimeout(() => {
                    this.hideLoading();
                }, 3000);
            }

            hideLoading() {
                this.loadingScreen.classList.add('hidden');
                setTimeout(() => {
                    this.loadingScreen.style.display = 'none';
                }, 1000);
            }
        }

        // ===== CUSTOM CURSOR SYSTEM =====
        class CustomCursor {
            constructor() {
                this.cursor = document.getElementById('customCursor');
                this.trails = [];
                this.maxTrails = 8;
                this.init();
            }

            init() {
                // Create cursor trails
                for (let i = 0; i < this.maxTrails; i++) {
                    const trail = document.createElement('div');
                    trail.className = 'cursor-trail';
                    document.body.appendChild(trail);
                    this.trails.push({
                        element: trail,
                        x: 0,
                        y: 0,
                        alpha: (this.maxTrails - i) / this.maxTrails
                    });
                }

                document.addEventListener('mousemove', (e) => this.updateCursor(e));
                document.addEventListener('mousedown', () => this.cursor.style.transform = 'scale(0.8)');
                document.addEventListener('mouseup', () => this.cursor.style.transform = 'scale(1)');
            }

            updateCursor(e) {
                this.cursor.style.left = e.clientX - 10 + 'px';
                this.cursor.style.top = e.clientY - 10 + 'px';

                // Update trails with delay
                this.trails.forEach((trail, index) => {
                    setTimeout(() => {
                        trail.element.style.left = e.clientX - 3 + 'px';
                        trail.element.style.top = e.clientY - 3 + 'px';
                        trail.element.style.opacity = trail.alpha * 0.7;
                    }, index * 20);
                });
            }
        }

        // ===== PARTICLES SYSTEM =====
        class ParticleSystem {
            constructor() {
                this.container = document.getElementById('particlesContainer');
                this.particles = [];
                this.maxParticles = 15;
                this.init();
            }

            init() {
                this.createParticles();
                setInterval(() => this.createParticle(), 3000);
            }

            createParticles() {
                for (let i = 0; i < this.maxParticles; i++) {
                    setTimeout(() => this.createParticle(), i * 1000);
                }
            }

            createParticle() {
                const particle = document.createElement('div');
                particle.className = 'particle';
                
                const size = Math.random() * 8 + 2;
                particle.style.width = size + 'px';
                particle.style.height = size + 'px';
                particle.style.left = Math.random() * 100 + '%';
                particle.style.animationDuration = (Math.random() * 10 + 10) + 's';
                particle.style.animationDelay = Math.random() * 2 + 's';

                this.container.appendChild(particle);

                // Remove particle after animation
                setTimeout(() => {
                    if (particle.parentNode) {
                        particle.parentNode.removeChild(particle);
                    }
                }, 20000);
            }
        }

        // ===== AMBIENT LIGHTING SYSTEM =====
        class AmbientLighting {
            constructor() {
                this.ambientLight = document.getElementById('ambientLight');
                this.init();
            }

            init() {
                document.addEventListener('mousemove', (e) => {
                    const x = (e.clientX / window.innerWidth) * 100;
                    const y = (e.clientY / window.innerHeight) * 100;
                    
                    document.documentElement.style.setProperty('--mouse-x', x + '%');
                    document.documentElement.style.setProperty('--mouse-y', y + '%');
                });
            }
        }

        // ===== QUOTE SYSTEM =====
        class QuoteSystem {
            constructor() {
                this.quotePopup = document.getElementById('quotePopup');
                this.selectedText = '';
                this.init();
            }

            init() {
                document.addEventListener('mouseup', () => this.handleSelection());
                document.addEventListener('click', (e) => {
                    if (!this.quotePopup.contains(e.target)) {
                        this.hideQuote();
                    }
                });

                // Quote actions
                this.quotePopup.addEventListener('click', (e) => {
                    if (e.target.classList.contains('quote-action')) {
                        const action = e.target.dataset.action;
                        this.handleQuoteAction(action);
                    }
                });
            }

            handleSelection() {
                const selection = window.getSelection();
                const text = selection.toString().trim();
                
                if (text.length > 10 && text.length < 200) {
                    this.selectedText = text;
                    this.showQuote(text, selection);
                } else {
                    this.hideQuote();
                }
            }

            showQuote(text, selection) {
                const range = selection.getRangeAt(0);
                const rect = range.getBoundingClientRect();
                
                this.quotePopup.querySelector('.quote-text').textContent = `"${text}"`;
                this.quotePopup.style.left = rect.left + 'px';
                this.quotePopup.style.top = (rect.bottom + 10) + 'px';
                this.quotePopup.classList.add('show');
            }

            hideQuote() {
                this.quotePopup.classList.remove('show');
            }

            handleQuoteAction(action) {
                if (action === 'copy') {
                    navigator.clipboard.writeText(this.selectedText).then(() => {
                        this.showFeedback('Citação copiada!');
                    });
                } else if (action === 'share') {
                    const text = `"${this.selectedText}" - Manifesto Marta Arcanjo`;
                    const url = window.location.href;
                    window.open(`https://twitter.com/intent/tweet?text=${encodeURIComponent(text)}&url=${encodeURIComponent(url)}`, '_blank');
                }
                this.hideQuote();
            }

            showFeedback(message) {
                const feedback = document.createElement('div');
                feedback.textContent = message;
                feedback.style.cssText = `
                    position: fixed;
                    top: 50%;
                    left: 50%;
                    transform: translate(-50%, -50%);
                    background: #2ecc71;
                    color: white;
                    padding: 10px 20px;
                    border-radius: 5px;
                    font-family: Inter, sans-serif;
                    font-weight: 500;
                    z-index: 10000;
                    animation: fadeInOut 2s ease-in-out forwards;
                `;
                
                document.body.appendChild(feedback);
                
                setTimeout(() => {
                    if (feedback.parentNode) {
                        feedback.parentNode.removeChild(feedback);
                    }
                }, 2000);
            }
        }

        // ===== ENHANCED EFFECTS SYSTEM =====
        class EffectsSystem {
            constructor() {
                this.init();
            }

            init() {
                this.setupHoverEffects();
                this.setupScrollEffects();
                this.setupSoundEffects();
            }

            setupHoverEffects() {
                // Ink splash effect on highlights
                document.addEventListener('mouseenter', (e) => {
                    if (e.target.classList.contains('highlight-word') || 
                        e.target.classList.contains('highlight-phrase')) {
                        this.createInkSplash(e.target);
                    }
                }, true);
            }

            setupScrollEffects() {
                let lastScrollTop = 0;
                window.addEventListener('scroll', () => {
                    const scrollTop = window.pageYOffset;
                    const scrollDirection = scrollTop > lastScrollTop ? 'down' : 'up';
                    
                    // Parallax effect on newspaper
                    const newspaper = document.querySelector('.newspaper-wrapper');
                    if (newspaper) {
                        const translateY = scrollTop * 0.1;
                        newspaper.style.transform = `perspective(1000px) rotateX(1deg) translateY(${translateY}px)`;
                    }
                    
                    lastScrollTop = scrollTop;
                });
            }

            setupSoundEffects() {
                // Visual sound waves for interactions
                document.addEventListener('click', (e) => {
                    if (e.target.classList.contains('feedback-btn') || 
                        e.target.closest('.feedback-btn')) {
                        this.createSoundWave(e.clientX, e.clientY);
                    }
                });
            }

            createInkSplash(element) {
                const rect = element.getBoundingClientRect();
                const splash = document.createElement('div');
                splash.className = 'ink-splash';
                splash.style.left = (rect.left + rect.width / 2) + 'px';
                splash.style.top = (rect.top + rect.height / 2) + 'px';
                
                document.body.appendChild(splash);
                
                setTimeout(() => {
                    if (splash.parentNode) {
                        splash.parentNode.removeChild(splash);
                    }
                }, 1500);
            }

            createSoundWave(x, y) {
                const wave = document.createElement('div');
                wave.className = 'sound-wave';
                wave.style.left = (x - 10) + 'px';
                wave.style.top = (y - 10) + 'px';
                
                document.body.appendChild(wave);
                
                setTimeout(() => {
                    if (wave.parentNode) {
                        wave.parentNode.removeChild(wave);
                    }
                }, 1000);
            }
        }
        class ImageCarousel {
            constructor() {
                this.currentSlide = 0;
                this.totalSlides = 5;
                this.track = document.getElementById('carouselTrack');
                this.prevBtn = document.getElementById('prevBtn');
                this.nextBtn = document.getElementById('nextBtn');
                this.indicators = document.querySelectorAll('.carousel-indicator');
                
                this.init();
            }

            init() {
                this.setupEventListeners();
                this.updateCarousel();
            }

            setupEventListeners() {
                this.prevBtn.addEventListener('click', () => this.previousSlide());
                this.nextBtn.addEventListener('click', () => this.nextSlide());
                
                this.indicators.forEach((indicator, index) => {
                    indicator.addEventListener('click', () => this.goToSlide(index));
                });

                // Auto-slide (opcional)
                setInterval(() => {
                    this.nextSlide();
                }, 8000);
            }

            previousSlide() {
                this.currentSlide = (this.currentSlide - 1 + this.totalSlides) % this.totalSlides;
                this.updateCarousel();
            }

            nextSlide() {
                this.currentSlide = (this.currentSlide + 1) % this.totalSlides;
                this.updateCarousel();
            }

            goToSlide(slideIndex) {
                this.currentSlide = slideIndex;
                this.updateCarousel();
            }

            updateCarousel() {
                const translateX = -this.currentSlide * 20; // 20% per slide
                this.track.style.transform = `translateX(${translateX}%)`;
                
                this.indicators.forEach((indicator, index) => {
                    indicator.classList.toggle('active', index === this.currentSlide);
                });
            }
        }

        // ===== PROGRESS BAR SYSTEM =====
        class ReadingProgress {
            constructor() {
                this.progressBar = document.getElementById('progressBar');
                this.init();
            }

            init() {
                window.addEventListener('scroll', () => this.updateProgress());
                this.updateProgress(); // Initial call
            }

            updateProgress() {
                const article = document.querySelector('.main-article');
                if (!article) return;

                const articleTop = article.offsetTop;
                const articleHeight = article.offsetHeight;
                const windowHeight = window.innerHeight;
                const scrollTop = window.pageYOffset || document.documentElement.scrollTop;

                // Calculate progress based on article reading
                const startReading = articleTop - windowHeight / 2;
                const endReading = articleTop + articleHeight - windowHeight / 2;
                const totalReadingDistance = endReading - startReading;

                let progress = 0;
                if (scrollTop > startReading) {
                    progress = Math.min(((scrollTop - startReading) / totalReadingDistance) * 100, 100);
                }

                this.progressBar.style.width = `${Math.max(0, progress)}%`;
            }
        }

        // ===== DARK MODE SYSTEM =====
        class DarkModeToggle {
            constructor() {
                this.darkModeToggle = document.getElementById('darkModeToggle');
                this.isDarkMode = false;
                this.init();
            }

            init() {
                // Check for saved preference or default to light mode
                try {
                    const savedMode = localStorage.getItem('darkMode');
                    if (savedMode === 'true') {
                        this.enableDarkMode();
                    }
                } catch (e) {
                    console.log('LocalStorage não disponível, usando configuração padrão');
                }

                this.darkModeToggle.addEventListener('click', () => this.toggle());
            }

            toggle() {
                if (this.isDarkMode) {
                    this.disableDarkMode();
                } else {
                    this.enableDarkMode();
                }
            }

            enableDarkMode() {
                document.body.classList.add('dark-mode');
                this.darkModeToggle.innerHTML = '☀️';
                this.isDarkMode = true;
                try {
                    localStorage.setItem('darkMode', 'true');
                } catch (e) {
                    console.log('Não foi possível salvar a preferência de modo noturno');
                }
                
                // Smooth transition effect
                this.darkModeToggle.style.animation = 'spin 0.5s ease-in-out';
                setTimeout(() => {
                    this.darkModeToggle.style.animation = '';
                }, 500);
            }

            disableDarkMode() {
                document.body.classList.remove('dark-mode');
                this.darkModeToggle.innerHTML = '🌙';
                this.isDarkMode = false;
                try {
                    localStorage.setItem('darkMode', 'false');
                } catch (e) {
                    console.log('Não foi possível salvar a preferência de modo claro');
                }
                
                // Smooth transition effect
                this.darkModeToggle.style.animation = 'spin 0.5s ease-in-out';
                setTimeout(() => {
                    this.darkModeToggle.style.animation = '';
                }, 500);
            }
        }

        // Add spin animation for dark mode toggle
        const spinKeyframes = `
            @keyframes spin {
                from { transform: rotate(0deg) scale(1.1); }
                to { transform: rotate(360deg) scale(1.1); }
            }
        `;
        document.head.appendChild(Object.assign(document.createElement('style'), { textContent: spinKeyframes }));

        // ===== SISTEMA DE FEEDBACK =====
        class FeedbackSystem {
            constructor() {
                this.data = {
                    likes: 0,
                    shares: {
                        facebook: 0,
                        twitter: 0,
                        linkedin: 0,
                        whatsapp: 0,
                        copy: 0
                    },
                    userInteractions: {
                        hasLiked: false,
                        hasShared: []
                    }
                };
                this.init();
            }
            async init() {
                await this.loadData();
                this.setupLikeButton();
                this.setupShareButton();
                this.updateLikeCount();
                this.updateShareCount();
                this.startLiveUpdates(); // Iniciar updates em tempo real
            }
            async loadData() {
                try {
                    const formData = new FormData();
                    formData.append('ajax_action', 'get_data');
                    
                    const response = await fetch(window.location.href, {
                        method: 'POST',
                        body: formData
                    });
                    
                    const result = await response.json();
                    
                    if (result.success) {
                        this.data.likes = result.data.likes;
                        this.data.shares = result.data.shares;
                        this.data.userInteractions.hasLiked = result.data.user.hasLiked;
                        this.data.userInteractions.hasShared = result.data.user.hasShared;
                    }
                } catch (error) {
                    console.error('Erro ao carregar dados:', error);
                }
            }
            setupLikeButton() {
                const likeBtn = document.getElementById('likeBtn');
                let isProcessing = false; // Prevenir cliques duplos
                
                likeBtn.addEventListener('click', async () => {
                    if (isProcessing) return; // Prevenir spam de cliques
                    isProcessing = true;
                    
                    await this.handleLike();
                    
                    // Pequeno delay para prevenir cliques rápidos
                    setTimeout(() => {
                        isProcessing = false;
                    }, 500);
                });
            }
            setupShareButton() {
                const shareBtn = document.getElementById('shareBtn');
                const shareModal = document.getElementById('shareModal');
                
                shareBtn.addEventListener('click', (e) => {
                    e.stopPropagation();
                    shareModal.classList.add('active');
                });
                // Fechar modal ao clicar no overlay
                shareModal.addEventListener('click', (e) => {
                    if (e.target === shareModal) {
                        shareModal.classList.remove('active');
                    }
                });
                // Fechar modal com ESC
                document.addEventListener('keydown', (e) => {
                    if (e.key === 'Escape' && shareModal.classList.contains('active')) {
                        shareModal.classList.remove('active');
                    }
                });
                // Configurar opções de partilha
                shareModal.addEventListener('click', (e) => {
                    if (e.target.closest('.share-option')) {
                        e.preventDefault(); // Prevenir scroll para o topo
                        const platform = e.target.closest('.share-option').dataset.platform;
                        this.handleShare(platform);
                        shareModal.classList.remove('active');
                    }
                });
            }
            async handleLike() {
                try {
                    const action = this.data.userInteractions.hasLiked ? 'unlike' : 'like';
                    
                    // UPDATE VISUAL IMEDIATO (Optimistic UI)
                    const previousLikes = this.data.likes;
                    const previousLikedStatus = this.data.userInteractions.hasLiked;
                    
                    if (action === 'like') {
                        this.data.likes++;
                        this.data.userInteractions.hasLiked = true;
                        this.createHeartAnimation();
                        this.animateLikeButton();
                    } else {
                        this.data.likes--;
                        this.data.userInteractions.hasLiked = false;
                    }
                    
                    this.updateLikeCount();
                    
                    // AJAX em background para confirmar no servidor
                    const formData = new FormData();
                    formData.append('ajax_action', action);
                    
                    const response = await fetch(window.location.href, {
                        method: 'POST',
                        body: formData
                    });
                    const result = await response.json();
                    if (result.success) {
                        // Sincronizar com dados reais do servidor
                        this.data.likes = result.data.likes;
                        this.data.userInteractions.hasLiked = result.data.user.hasLiked;
                        this.data.userInteractions.hasShared = result.data.user.hasShared || this.data.userInteractions.hasShared;
                        this.updateLikeCount();
                    } else {
                        // Se falhou, reverter mudanças visuais
                        console.error('Erro no servidor:', result.error);
                        this.data.likes = previousLikes;
                        this.data.userInteractions.hasLiked = previousLikedStatus;
                        this.updateLikeCount();
                        
                        // Mostrar erro ao utilizador
                        this.showErrorFeedback('Erro ao processar like. Tente novamente.');
                    }
                } catch (error) {
                    console.error('Erro ao processar like:', error);
                    // Em caso de erro de rede, não reverter - assumir que funcionou
                    this.showErrorFeedback('Verificação no servidor falhou, mas like foi registado.');
                }
            }
            async handleShare(platform) {
                const url = window.location.href;
                const title = "Manifesto - Marta Arcanjo";
                const text = "Talvez esteja na hora, de um Novo Manifesto";
                switch (platform) {
                    case 'facebook':
                        window.open(`https://www.facebook.com/sharer/sharer.php?u=${encodeURIComponent(url)}`, '_blank');
                        break;
                    case 'twitter':
                        window.open(`https://twitter.com/intent/tweet?text=${encodeURIComponent(text)}&url=${encodeURIComponent(url)}`, '_blank');
                        break;
                    case 'linkedin':
                        window.open(`https://www.linkedin.com/sharing/share-offsite/?url=${encodeURIComponent(url)}`, '_blank');
                        break;
                    case 'whatsapp':
                        window.open(`https://wa.me/?text=${encodeURIComponent(text + ' ' + url)}`, '_blank');
                        break;
                    case 'copy':
                        navigator.clipboard.writeText(url).then(() => {
                            this.showCopyFeedback();
                        });
                        break;
                }
                // Registrar partilha na base de dados
                try {
                    const formData = new FormData();
                    formData.append('ajax_action', 'share');
                    formData.append('platform', platform);
                    
                    const response = await fetch(window.location.href, {
                        method: 'POST',
                        body: formData
                    });
                    const result = await response.json();
                    console.log(`Partilha registrada: ${platform}`, result);
                } catch (error) {
                    console.error('Erro ao registrar partilha:', error);
                }
            }
            createHeartAnimation() {
                const heartsCount = 3;
                const likeBtn = document.getElementById('likeBtn');
                const btnRect = likeBtn.getBoundingClientRect();
                for (let i = 0; i < heartsCount; i++) {
                    setTimeout(() => {
                        const heart = document.createElement('div');
                        heart.className = 'heart-animation';
                        heart.innerHTML = '👍';
                        
                        // Posição aleatória ao redor do botão
                        const randomX = btnRect.left + btnRect.width/2 + Math.random() * 60 - 30;
                        const randomY = btnRect.top + btnRect.height/2 + Math.random() * 40 - 20;
                        
                        heart.style.left = randomX + 'px';
                        heart.style.top = randomY + 'px';
                        
                        document.body.appendChild(heart);
                        
                        // Remover após animação
                        setTimeout(() => {
                            if (heart.parentNode) {
                                heart.parentNode.removeChild(heart);
                            }
                        }, 2000);
                    }, i * 200);
                }
            }
            animateLikeButton() {
                const likeBtn = document.getElementById('likeBtn');
                likeBtn.style.animation = 'likeAnimation 0.6s ease-in-out';
                
                setTimeout(() => {
                    likeBtn.style.animation = '';
                }, 600);
            }
            updateLikeCount() {
                const likeCount = document.getElementById('likeCount');
                const likeBtn = document.getElementById('likeBtn');
                
                // Animate counter
                this.animateCounter(likeCount, parseInt(likeCount.textContent), this.data.likes);
                
                if (this.data.userInteractions.hasLiked) {
                    likeBtn.style.color = '#ef4444';
                    likeBtn.classList.add('liked');
                } else {
                    likeBtn.style.color = '#6b7280';
                    likeBtn.classList.remove('liked');
                }
            }
            
            updateShareCount() {
                const shareCount = document.getElementById('shareCount');
                
                // Calculate total shares
                const newTotal = Object.values(this.data.shares).reduce((total, count) => total + count, 0);
                const oldTotal = parseInt(shareCount.textContent);
                
                // Animate counter
                this.animateCounter(shareCount, oldTotal, newTotal);
            }
            
            animateCounter(element, from, to) {
                if (from === to) return;
                
                element.classList.add('count-animation');
                
                const duration = 500;
                const startTime = performance.now();
                const diff = to - from;
                
                const animate = (currentTime) => {
                    const elapsed = currentTime - startTime;
                    const progress = Math.min(elapsed / duration, 1);
                    
                    const easeOutCubic = 1 - Math.pow(1 - progress, 3);
                    const currentValue = Math.round(from + (diff * easeOutCubic));
                    
                    element.textContent = currentValue;
                    
                    if (progress < 1) {
                        requestAnimationFrame(animate);
                    } else {
                        element.classList.remove('count-animation');
                    }
                };
                
                requestAnimationFrame(animate);
            }
            // Sistema de Live Updates
            startLiveUpdates() {
                // Atualizar a cada 15 segundos (aumentei um pouco para reduzir carga)
                setInterval(async () => {
                    await this.checkForUpdates();
                }, 15000);
            }
            async checkForUpdates() {
                try {
                    const formData = new FormData();
                    formData.append('ajax_action', 'get_data');
                    
                    const response = await fetch(window.location.href, {
                        method: 'POST',
                        body: formData
                    });
                    
                    const result = await response.json();
                    
                    if (result.success) {
                        // Verificar se houve mudanças nos likes (só atualizar se maior que o atual)
                        if (result.data.likes > this.data.likes) {
                            const previousLikes = this.data.likes;
                            this.data.likes = result.data.likes;
                            this.updateLikeCount();
                            this.showLiveUpdateNotification('likes', result.data.likes);
                        }
                        
                        // Verificar se houve mudanças nas partilhas (só atualizar se maior)
                        const newTotalShares = Object.values(result.data.shares).reduce((total, count) => total + count, 0);
                        const currentTotalShares = Object.values(this.data.shares).reduce((total, count) => total + count, 0);
                        
                        if (newTotalShares > currentTotalShares) {
                            this.data.shares = result.data.shares;
                            this.updateShareCount();
                            this.showLiveUpdateNotification('shares', newTotalShares);
                        }
                    }
                } catch (error) {
                    // Silencioso - não mostrar erros de live updates
                    console.log('Live update check failed:', error);
                }
            }
            showLiveUpdateNotification(type, count) {
                const notification = document.createElement('div');
                notification.style.cssText = `
                    position: fixed;
                    top: 20px;
                    right: 20px;
                    background: linear-gradient(135deg, #10b981, #059669);
                    color: white;
                    padding: 8px 12px;
                    border-radius: 6px;
                    font-family: Inter, sans-serif;
                    font-size: 12px;
                    font-weight: 500;
                    z-index: 10001;
                    box-shadow: 0 3px 10px rgba(0, 0, 0, 0.15);
                    animation: slideInRight 0.3s ease-out;
                `;
                
                const icon = type === 'likes' ? '👍' : '📤';
                const text = type === 'likes' ? 'likes' : 'partilhas';
                notification.innerHTML = `${icon} ${count} ${text}`;
                
                document.body.appendChild(notification);
                
                setTimeout(() => {
                    notification.style.animation = 'slideOutRight 0.3s ease-in forwards';
                    setTimeout(() => {
                        if (notification.parentNode) {
                            notification.parentNode.removeChild(notification);
                        }
                    }, 300);
                }, 2500);
            }
            showCopyFeedback() {
                // Criar feedback visual para cópia
                const feedback = document.createElement('div');
                feedback.textContent = 'Link copiado!';
                feedback.style.cssText = `
                    position: fixed;
                    top: 50%;
                    left: 50%;
                    transform: translate(-50%, -50%);
                    background: #2ecc71;
                    color: white;
                    padding: 10px 20px;
                    border-radius: 5px;
                    font-family: Inter, sans-serif;
                    font-weight: 500;
                    z-index: 10000;
                    animation: fadeInOut 2s ease-in-out forwards;
                `;
                
                document.body.appendChild(feedback);
                
                setTimeout(() => {
                    if (feedback.parentNode) {
                        feedback.parentNode.removeChild(feedback);
                    }
                }, 2000);
            }
            showErrorFeedback(message) {
                // Criar feedback visual para erros
                const feedback = document.createElement('div');
                feedback.textContent = message;
                feedback.style.cssText = `
                    position: fixed;
                    top: 50%;
                    left: 50%;
                    transform: translate(-50%, -50%);
                    background: #e74c3c;
                    color: white;
                    padding: 10px 20px;
                    border-radius: 5px;
                    font-family: Inter, sans-serif;
                    font-weight: 500;
                    z-index: 10000;
                    animation: fadeInOut 3s ease-in-out forwards;
                    max-width: 300px;
                    text-align: center;
                `;
                
                document.body.appendChild(feedback);
                
                setTimeout(() => {
                    if (feedback.parentNode) {
                        feedback.parentNode.removeChild(feedback);
                    }
                }, 3000);
            }
            // Método para exportar dados (para desenvolvimento)
            async exportData() {
                await this.loadData();
                console.log('📊 Estado atual completo:', {
                    likes: this.data.likes,
                    totalShares: Object.values(this.data.shares).reduce((total, count) => total + count, 0),
                    shares: this.data.shares,
                    userInteractions: this.data.userInteractions
                });
                return JSON.stringify(this.data, null, 2);
            }
        }
        // Adicionar estilo para animação de fade in/out
        const style = document.createElement('style');
        style.textContent = `
            @keyframes fadeInOut {
                0% { opacity: 0; transform: translate(-50%, -50%) scale(0.8); }
                20% { opacity: 1; transform: translate(-50%, -50%) scale(1); }
                80% { opacity: 1; transform: translate(-50%, -50%) scale(1); }
                100% { opacity: 0; transform: translate(-50%, -50%) scale(0.8); }
            }
            
            @keyframes slideInRight {
                from {
                    opacity: 0;
                    transform: translateX(100px);
                }
                to {
                    opacity: 1;
                    transform: translateX(0);
                }
            }
            
            @keyframes slideOutRight {
                from {
                    opacity: 1;
                    transform: translateX(0);
                }
                to {
                    opacity: 0;
                    transform: translateX(100px);
                }
            }
        `;
        document.head.appendChild(style);
        function escaparRegex(texto) {
            return texto.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
        }
        function aplicarDestaques() {
            if (ATIVAR_DESTAQUES !== 1) return;
            
            const container = document.querySelector('.manifesto-content');
            let html = container.innerHTML;
            
            destaques.caixas.forEach(texto => {
                const regex = new RegExp(`(${escaparRegex(texto)})`, 'gi');
                html = html.replace(regex, '<span class="highlight-box-content">$1</span>');
            });
            
            destaques.fortes.forEach(texto => {
                const regex = new RegExp(`(${escaparRegex(texto)})`, 'gi');
                html = html.replace(regex, '<span class="highlight-strong">$1</span>');
            });
            
            destaques.frases.forEach(frase => {
                const regex = new RegExp(`(${escaparRegex(frase)})`, 'gi');
                html = html.replace(regex, '<span class="highlight-phrase">$1</span>');
            });
            
            destaques.palavras.forEach(palavra => {
                const regex = new RegExp(`(?<!<span[^>]*>.*?)\\b(${escaparRegex(palavra)})\\b(?![^<]*</span>)`, 'gi');
                html = html.replace(regex, '<span class="highlight-word">$1</span>');
            });
            
            container.innerHTML = html;
            
            const caixasContent = container.querySelectorAll('.highlight-box-content');
            caixasContent.forEach(span => {
                const verso = span.closest('.verse');
                if (verso) {
                    verso.classList.add('highlight-box');
                    span.replaceWith(...span.childNodes);
                }
            });
        }
        function removerDestaques() {
            const container = document.querySelector('.manifesto-content');
            
            container.querySelectorAll('.highlight-word, .highlight-phrase, .highlight-strong').forEach(el => {
                el.replaceWith(...el.childNodes);
            });
            
            container.querySelectorAll('.highlight-box').forEach(el => {
                el.classList.remove('highlight-box');
            });
        }
        function controlarDestaques() {
            removerDestaques();
            if (ATIVAR_DESTAQUES === 1) {
                aplicarDestaques();
            }
        }
        // Funcões globais para console
        window.ativarDestaques = function() {
            window.ATIVAR_DESTAQUES = 1;
            controlarDestaques();
            console.log("Destaques ativados!");
        };
        
        window.desativarDestaques = function() {
            window.ATIVAR_DESTAQUES = 0;
            controlarDestaques();
            console.log("Destaques desativados!");
        };
        // Inicializar sistemas
        let feedbackSystem;
        let carousel;
        let readingProgress;
        let darkModeToggle;
        let loadingSystem;
        let customCursor;
        let particleSystem;
        let ambientLighting;
        let quoteSystem;
        let effectsSystem;
        
        window.addEventListener('load', function() {
            // Initialize all systems
            loadingSystem = new LoadingSystem();
            customCursor = new CustomCursor();
            particleSystem = new ParticleSystem();
            ambientLighting = new AmbientLighting();
            quoteSystem = new QuoteSystem();
            effectsSystem = new EffectsSystem();
            
            controlarDestaques();
            feedbackSystem = new FeedbackSystem();
            carousel = new ImageCarousel();
            readingProgress = new ReadingProgress();
            darkModeToggle = new DarkModeToggle();
            
            // Função global para exportar dados de feedback
            window.exportarDadosFeedback = async function() {
                if (feedbackSystem) {
                    const data = await feedbackSystem.exportData();
                    console.log('📊 Dados de feedback exportados:', data);
                    return data;
                } else {
                    console.log('Sistema de feedback ainda não inicializado');
                    return null;
                }
            };
            
            // Debug functions
            window.debugSystems = function() {
                console.log('🎛️ Sistemas ativados:', {
                    loading: !!loadingSystem,
                    cursor: !!customCursor,
                    particles: !!particleSystem,
                    lighting: !!ambientLighting,
                    quotes: !!quoteSystem,
                    effects: !!effectsSystem,
                    feedback: !!feedbackSystem,
                    carousel: !!carousel,
                    progress: !!readingProgress,
                    darkMode: !!darkModeToggle
                });
            };
        });
        
        setTimeout(() => {
            controlarDestaques();
            if (!feedbackSystem) {
                feedbackSystem = new FeedbackSystem();
            }
            if (!carousel) {
                carousel = new ImageCarousel();
            }
            if (!readingProgress) {
                readingProgress = new ReadingProgress();
            }
            if (!darkModeToggle) {
                darkModeToggle = new DarkModeToggle();
            }
            if (!customCursor) {
                customCursor = new CustomCursor();
            }
            if (!particleSystem) {
                particleSystem = new ParticleSystem();
            }
            if (!ambientLighting) {
                ambientLighting = new AmbientLighting();
            }
            if (!quoteSystem) {
                quoteSystem = new QuoteSystem();
            }
            if (!effectsSystem) {
                effectsSystem = new EffectsSystem();
            }
        }, 100);
    </script>
</body>
</html>