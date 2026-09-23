<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

$baseUrl = rtrim((string) app_env('APP_URL'), '/');

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
?>
<!doctype html>
<html lang="bg">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Как работи системата | Граждански сигнали</title>
    <meta name="description" content="Как работи системата за подаване и насочване на граждански сигнали.">
    <style>
        :root {
            --bg: #f5f7fa;
            --card: #ffffff;
            --text: #1f2937;
            --muted: #667085;
            --primary: #0759b8;
            --primary-dark: #06458e;
            --border: #e5e7eb;
            --soft: #eef5ff;
            --warning: #fff7e6;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            background: var(--bg);
            color: var(--text);
            font-family: system-ui, -apple-system, "Segoe UI", Roboto, Arial, sans-serif;
            line-height: 1.6;
        }
        .wrap { max-width: 920px; margin: 0 auto; padding: 28px 18px 50px; }
        .top {
            display: flex;
            justify-content: space-between;
            gap: 16px;
            align-items: center;
            margin-bottom: 24px;
            flex-wrap: wrap;
        }
        .brand { font-weight: 800; color: var(--text); text-decoration: none; }
        .back { color: var(--primary); text-decoration: none; font-weight: 700; }
        .card {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 28px;
            box-shadow: 0 4px 16px rgba(15, 23, 42, .05);
            margin-bottom: 18px;
        }
        h1 { margin: 0 0 12px; font-size: clamp(1.8rem, 4vw, 2.35rem); line-height: 1.2; }
        h2 { margin: 28px 0 10px; font-size: 1.35rem; }
        h3 { margin: 0 0 8px; font-size: 1.08rem; }
        p { margin: 0 0 12px; }
        ul { margin: 8px 0 14px; padding-left: 1.35rem; }
        li { margin: 7px 0; }
        .lead { color: var(--muted); font-size: 1.06rem; }
        .steps { display: grid; gap: 12px; margin-top: 16px; }
        .step {
            display: grid;
            grid-template-columns: 42px 1fr;
            gap: 13px;
            align-items: start;
            padding: 14px;
            border: 1px solid var(--border);
            border-radius: 10px;
        }
        .number {
            width: 36px;
            height: 36px;
            display: grid;
            place-items: center;
            border-radius: 50%;
            background: var(--primary);
            color: #fff;
            font-weight: 800;
        }
        .note {
            background: var(--soft);
            border-left: 4px solid var(--primary);
            padding: 14px 16px;
            border-radius: 7px;
            margin: 16px 0;
        }
        .warning {
            background: var(--warning);
            border-left: 4px solid #d97706;
            padding: 14px 16px;
            border-radius: 7px;
            margin: 16px 0;
        }
        .actions { display: flex; gap: 10px; flex-wrap: wrap; margin-top: 22px; }
        .btn {
            display: inline-block;
            padding: 10px 16px;
            border-radius: 8px;
            text-decoration: none;
            font-weight: 700;
            border: 1px solid var(--primary);
        }
        .btn-primary { background: var(--primary); color: #fff; }
        .btn-primary:hover { background: var(--primary-dark); }
        .btn-outline { color: var(--primary); background: #fff; }
        code { background: #f1f3f5; padding: 2px 5px; border-radius: 4px; }
        @media (max-width: 640px) {
            .card { padding: 20px; }
            .wrap { padding-top: 18px; }
        }
    </style>
</head>
<body>
<main class="wrap">
    <div class="top">
        <a class="brand" href="<?= e($baseUrl . '/') ?>">Граждански сигнали</a>
        <a class="back" href="<?= e($baseUrl . '/') ?>">← Към подаване на сигнал</a>
    </div>

    <section class="card">
        <h1>Как работи системата</h1>
        <p class="lead">
            Платформата дава възможност на граждани да подадат информация за нередност,
            като използват оригинална снимка с GPS данни и дата и час на заснемане.
        </p>

        <div class="steps">
            <div class="step">
                <div class="number">1</div>
                <div>
                    <h3>Избирате снимка</h3>
                    <p>
                        Избирате една оригинална снимка на проблема. Системата проверява
                        файла и неговите EXIF данни.
                    </p>
                </div>
            </div>

            <div class="step">
                <div class="number">2</div>
                <div>
                    <h3>Проверяваме местоположението и времето на заснемане</h3>
                    <p>
                        За да бъде приет сигналът, от оригиналната снимка трябва да бъдат
                        открити валидни GPS координати и <code>DateTimeOriginal</code>.
                        Системата не използва местоположение от IP адрес или от
                        браузърната геолокация като заместител на тези данни.
                    </p>
                </div>
            </div>

            <div class="step">
                <div class="number">3</div>
                <div>
                    <h3>Въвеждате e-mail</h3>
                    <p>
                        Посочвате e-mail адрес, на който получавате връзка за потвърждение.
                        Така се проверява, че адресът може да получава съобщения от системата.
                    </p>
                </div>
            </div>

            <div class="step">
                <div class="number">4</div>
                <div>
                    <h3>Потвърждавате сигнала</h3>
                    <p>
                        Отваряте получената връзка. Едва след потвърждението системата
                        подготвя сигнала за насочване към съответната община.
                    </p>
                </div>
            </div>

            <div class="step">
                <div class="number">5</div>
                <div>
                    <h3>Сигналът се насочва към съответната община</h3>
                    <p>
                        По GPS координатите се определя общината, към която е насочен
                        сигналът. На посочения от общината адрес се изпраща автоматично
                        съобщение с данните за сигнала.
                    </p>
                </div>
            </div>
        </div>
    </section>

    <section class="card">
        <h2>Каква информация се използва</h2>
        <ul>
            <li>оригиналната снимка, изпратена от подателя;</li>
            <li>GPS координатите, записани в EXIF данните на снимката;</li>
            <li>датата и часът на заснемане, записани в EXIF данните;</li>
            <li>описанието на проблема, ако е въведено;</li>
            <li>e-mail адресът на подателя за потвърждение и, когато е дадено разрешение, за евентуален контакт.</li>
        </ul>

        <div class="note">
            <strong>Важно:</strong> местоположението се извлича от данните в снимката.
            При някои телефони или приложения споделянето на снимка може автоматично
            да премахне GPS и други метаданни. Ако системата не открие GPS данни,
            снимката няма да бъде приета.
        </div>

        <p>
            За Android телефони има отделна
            <a href="<?= e($baseUrl . '/android-gps-help.php') ?>">помощ при липсващи GPS данни</a>.
        </p>
    </section>

    <section class="card">
    <section class="card">
    <h2>Как системата определя общината</h2>

    <p>
        GPS координатите от оригиналната снимка се използват за определяне
        на общината, на чиято територия се намира мястото на сигнала.
    </p>

    <p>
        За тази цел платформата използва географските граници на общините
        от ресурса <strong>SU_BG_NSI_LAU_2024_1</strong>, версия
        <strong>v2024.1</strong>, предоставен от
        <strong>Националния статистически институт (НСИ)</strong>.
    </p>

    <p>
        Данните са за състоянието на границите към
        <strong>31.12.2024 г.</strong> и обхващат 265 общини в България.
    </p>

    <p>
        Източник:
        <a
            href="https://www.nsi.bg/nrnm/pages/za-nrnm"
            target="_blank"
            rel="noopener noreferrer"
        >
            Национален статистически институт (НСИ)
        </a>.
    </p>
</section>
        <h2>Какво се случва след изпращането</h2>
        <p>
            Всеки сигнал получава уникален публичен номер. Данните се съхраняват в
            системата, а изпращането към общината се извършва автоматично след
            потвърждение на e-mail адреса.
        </p>

        <div class="warning">
            <strong>Важно уточнение:</strong> платформата е независима техническа
            система и не е част от общинска или друга държавна администрация.
            Тя не взема решения вместо получаващата администрация. Конкретната
            администрация определя по компетентност и приложимия ред как ще разгледа
            получения сигнал.
        </div>
    </section>

    <section class="card">
        <h2>Защо има потвърждение по e-mail?</h2>
        <p>
            Потвърждението намалява риска от използване на чужд e-mail адрес и от
            автоматично генерирани или злоупотребяващи подавания. Връзката за
            потвърждение е ограничена във времето и е предназначена за еднократна
            употреба.
        </p>

        <h2>Какво не прави системата</h2>
        <ul>
            <li>не използва GPS от браузъра вместо GPS от снимката;</li>
            <li>не използва IP геолокация като заместител на GPS данните от снимката;</li>
            <li>не приема снимка без необходимите EXIF данни;</li>
            <li>не гарантира, че получаващата администрация ще приеме сигнала за разглеждане по определен административен ред.</li>
        </ul>

        <div class="actions">
            <a class="btn btn-primary" href="<?= e($baseUrl . '/') ?>">Подай сигнал</a>
            <a class="btn btn-outline" href="<?= e($baseUrl . '/privacy.php') ?>">Поверителност и лични данни</a>
        </div>
    </section>
</main>
</body>
</html>