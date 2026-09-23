<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
?>
<!doctype html>
<html lang="bg">
<head>
    <meta charset="utf-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1"
    >

    <title>Помощ при липсващи GPS данни</title>

    <style>
        body {
            font-family: system-ui, -apple-system, BlinkMacSystemFont,
                "Segoe UI", sans-serif;
            background: #f5f7fa;
            margin: 0;
            color: #20242a;
        }

        .card {
            max-width: 700px;
            margin: 2rem auto;
            padding: 2rem;
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 2px 12px rgba(0, 0, 0, .12);
        }

        h1 {
            margin-top: 0;
            margin-bottom: 1rem;
            line-height: 1.25;
        }

        h2 {
            margin-top: 2rem;
            margin-bottom: .7rem;
            line-height: 1.3;
        }

        p {
            line-height: 1.55;
        }

        .intro {
            color: #3f464d;
        }

        .important {
            margin: 1.3rem 0;
            padding: 1rem;
            background: #f0f4f8;
            border-left: 4px solid #0759b8;
            border-radius: 6px;
            line-height: 1.55;
        }

        .warning {
            margin: 1.3rem 0;
            padding: 1rem;
            background: #fff7e6;
            border-left: 4px solid #d97706;
            border-radius: 6px;
            line-height: 1.55;
        }

        .step {
            margin: 1.5rem 0;
            padding: 1rem 1.1rem;
            background: #f8f9fa;
            border: 1px solid #dfe3e7;
            border-radius: 8px;
        }

        .step-title {
            font-weight: 700;
            margin-bottom: .55rem;
            font-size: 1.05rem;
        }

        .number {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 1.7rem;
            height: 1.7rem;
            margin-right: .4rem;
            background: #0759b8;
            color: #fff;
            border-radius: 50%;
            font-weight: 700;
        }

        .path {
            display: inline-block;
            margin: .5rem 0;
            padding: .45rem .7rem;
            background: #eef2f6;
            border-radius: 5px;
            font-weight: 600;
        }

        .success {
            margin: 1.5rem 0;
            padding: 1rem;
            background: #eef8f1;
            border-left: 4px solid #18733c;
            border-radius: 6px;
            line-height: 1.55;
        }

        .tip {
            margin-top: 1.5rem;
            padding: 1rem;
            background: #f8f9fa;
            border: 1px solid #dfe3e7;
            border-radius: 8px;
            line-height: 1.55;
        }

        .back {
            display: block;
            width: 100%;
            box-sizing: border-box;
            margin-top: 1.8rem;
            padding: .85rem 1rem;
            background: #0759b8;
            color: #fff;
            border-radius: 6px;
            text-align: center;
            text-decoration: none;
            font-weight: 700;
        }

        .back:hover {
            background: #064d9e;
        }

        .image {
            display: block;
            max-width: 100%;
            height: auto;
            margin: 1rem auto;
            border-radius: 8px;
        }

        ul {
            line-height: 1.6;
        }

        @media (max-width: 740px) {
            .card {
                margin: 1rem;
                padding: 1.4rem;
            }
        }
    </style>
</head>

<body>

<main class="card">

    <h1>Помощ при липсващи GPS данни</h1>

    <p class="intro">
        Ако при избора на снимка системата съобщи, че не са намерени
        GPS координати, а използвате телефон с Android, причината
        може да е начинът, по който телефонът предоставя снимката
        на браузъра.
    </p>

    <div class="important">
        <strong>Важно:</strong>
        Възможно е самата снимка да съдържа GPS координати,
        но приложението за избор на снимки да ги премахне,
        преди снимката да бъде предоставена на сайта.
    </div>

    <h2>Как да изберете снимката</h2>

    <div class="step">
        <div class="step-title">
            <span class="number">1</span>
            Натиснете „Избери снимка“
        </div>

        <p>
            След това телефонът ще ви предложи начин за избор
            на снимка или файл.
        </p>
    </div>

    <div class="step">
        <div class="step-title">
            <span class="number">2</span>
            Изберете „Файлове“ или „Файлов мениджър“
        </div>

        <p>
            Името на приложението може да е различно според
            производителя на телефона.
        </p>

        <p>
            Възможно е да видите например:
        </p>

        <ul>
            <li>Файлове</li>
            <li>Файлов мениджър</li>
            <li>Files</li>
            <li>Material Files</li>
            <li>друго приложение за управление на файлове</li>
        </ul>
    </div>

    <div class="step">
        <div class="step-title">
            <span class="number">3</span>
            Изберете режим „Всички файлове“
        </div>

        <p>
            Ако файловият мениджър предлага избор между
            <strong>„Изображения“</strong> и
            <strong>„Всички файлове“</strong>,
            изберете:
        </p>

        <p>
            <span class="path">Всички файлове</span>
        </p>

        <p>
            Не избирайте режима „Изображения“, ако преди това
            системата е съобщила, че липсват GPS координати.
        </p>
    </div>

    <div class="step">
        <div class="step-title">
            <span class="number">4</span>
            Намерете оригиналната снимка
        </div>

        <p>
            Отидете до папката, в която се намира снимката.
            Например:
        </p>

        <p>
            <span class="path">DCIM → Camera</span>
        </p>

        <p>
            или друга папка, в която телефонът съхранява
            оригиналните снимки.
        </p>
    </div>

    <div class="step">
        <div class="step-title">
            <span class="number">5</span>
            Изберете снимката
        </div>

        <p>
            Изберете оригиналния JPEG файл и изчакайте
            автоматичната проверка.
        </p>
    </div>

    <div class="success">
        <strong>Ако всичко е наред:</strong>
        системата ще открие GPS координатите и датата и часа
        на заснемане и ще позволи да продължите с подаването
        на сигнала.
    </div>

    <h2>Защо се случва това?</h2>

    <p>
        Съвременните телефони могат да записват GPS координатите
        в информацията към снимката (EXIF). Тази информация е
        необходима на системата, за да определи местоположението
        на сигнала.
    </p>

    <p>
        Някои приложения за избор или споделяне на снимки обаче
        могат автоматично да премахнат информацията за
        местоположението от снимката с цел защита на личните данни.
    </p>

    <div class="warning">
        <strong>Важно:</strong>
        Това не означава непременно, че GPS информацията липсва
        в оригиналната снимка. Възможно е тя да бъде премахната
        от телефона при избора на снимката.
    </div>

    <h2>Ако проблемът продължава</h2>

    <p>
        Уверете се, че използвате <strong>оригиналната снимка</strong>,
        направена с камерата на телефона, а не снимка, която е
        била изпратена през приложение за съобщения, социална
        мрежа или друга услуга.
    </p>

    <p>
        Ако имате възможност, опитайте отново като изберете
        снимката чрез файлов мениджър в режим
        <strong>„Всички файлове“</strong>.
    </p>

    <div class="tip">
        <strong>Съвет:</strong>
        Ако снимката е направена току-що, можете също да използвате
        опцията за заснемане на нова снимка, ако тя е налична при
        избора на файл. При правилно настроена камера новата снимка
        ще съдържа необходимите GPS данни.
    </div>

    <a
        class="back"
        href="index.php"
    >
        ← Обратно към подаване на сигнал
    </a>

</main>

</body>
</html>