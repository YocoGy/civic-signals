<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

/*
 * Lightweight email queue processing.
 *
 * The public page gives the email queue a chance to process
 * pending messages without requiring a permanently running worker.
 *
 * The queue service handles leasing, retries and failures.
 * Email errors must never prevent the public page from loading.
 */
try {
    $pdo = \App\Database\Connection::database();
    $mailer = new \App\Services\SmtpMailer();

    \App\Services\EmailQueueService::processPending(
        $pdo,
        $mailer,
        'validation',
        5
    );

    \App\Services\EmailQueueService::processPending(
        $pdo,
        $mailer,
        'municipality_dispatch',
        5
    );

} catch (\Throwable $e) {

    /*
     * Queue processing is auxiliary functionality.
     * A temporary SMTP/database problem must not break
     * the public signal submission page.
     */
    error_log(
        'Public page email queue processing failed: ' .
        $e->getMessage()
    );
}

$csrf = \App\Security\CSRF::token();
?>
<!doctype html>
<html lang="bg">
<head>
    <meta charset="utf-8">
    <meta
        name="viewport"
        content="width=device-width, initial-scale=1"
    >

    <title>Подай сигнал</title>

    <style>
        body {
            font-family: system-ui, -apple-system, BlinkMacSystemFont,
                "Segoe UI", sans-serif;
            background: #f5f7fa;
            margin: 0;
            color: #20242a;
        }

        .card {
            max-width: 560px;
            margin: 3rem auto;
            padding: 2rem;
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 2px 12px rgba(0, 0, 0, .12);
        }

        h1 {
            margin-top: 0;
            margin-bottom: 1rem;
        }

        .intro {
            line-height: 1.55;
            color: #3f464d;
        }

        .important {
            margin: 1.2rem 0;
            padding: 1rem;
            background: #f0f4f8;
            border-left: 4px solid #0759b8;
            border-radius: 6px;
            line-height: 1.5;
        }

        .field-title {
            display: block;
            font-weight: 700;
            margin-top: 1.4rem;
            margin-bottom: .45rem;
        }

        /*
         * The real file input is hidden.
         * The visible button below opens it.
         */
        #photo {
            position: absolute;
            width: 1px;
            height: 1px;
            opacity: 0;
            pointer-events: none;
        }

        .file-button {
            display: inline-block;
            width: 100%;
            box-sizing: border-box;
            padding: .8rem 1rem;
            background: #0759b8;
            color: #fff;
            border: 0;
            border-radius: 6px;
            font-weight: 700;
            text-align: center;
            cursor: pointer;
        }

        .file-button:hover {
            background: #064d9e;
        }

        .file-button:focus-within {
            outline: 3px solid rgba(7, 89, 184, .25);
        }

        .requirements {
            margin-top: .55rem;
            font-size: .9rem;
            color: #606870;
            line-height: 1.45;
        }

        #preview {
            max-width: 100%;
            max-height: 260px;
            display: none;
            margin: 1rem 0;
            border-radius: 6px;
        }

        #file-info {
            margin: .7rem 0;
            min-height: 1.4em;
            line-height: 1.45;
        }

        .valid {
            color: #18733c;
        }

        .invalid {
            color: #b42318;
        }

        input[type="email"] {
            display: block;
            width: 100%;
            box-sizing: border-box;
            padding: .75rem;
            margin: 0;
            border: 1px solid #c7cdd4;
            border-radius: 6px;
            font: inherit;
        }

        input[type="email"]:focus {
            outline: none;
            border-color: #0759b8;
            box-shadow: 0 0 0 3px rgba(7, 89, 184, .12);
        }
        
        .gps-help {
            display: none;
            margin-top: .8rem;
            padding: .8rem;
            background: #fff7e6;
            border-left: 4px solid #d97706;
            border-radius: 6px;
            line-height: 1.5;
        }

        .gps-help a {
            color: #0759b8;
            font-weight: 700;
        }

        .email-help {
            margin-top: .5rem;
            font-size: .9rem;
            color: #606870;
            line-height: 1.45;
        }

        .privacy {
            margin-top: 1.4rem;
            padding: 1rem;
            background: #f8f9fa;
            border: 1px solid #dfe3e7;
            border-radius: 8px;
            line-height: 1.5;
        }

        .privacy label {
            display: flex;
            align-items: flex-start;
            gap: .65rem;
            cursor: pointer;
        }

        .privacy input[type="checkbox"] {
            width: auto;
            margin-top: .25rem;
            flex: 0 0 auto;
        }

        .privacy-links {
            margin-top: .65rem;
            padding-left: 1.65rem;
            font-size: .9rem;
        }

        .privacy a {
            color: #0759b8;
        }

        button[type="submit"] {
            display: block;
            width: 100%;
            box-sizing: border-box;
            margin-top: 1.4rem;
            padding: .85rem 1rem;
            background: #0759b8;
            color: #fff;
            border: 0;
            border-radius: 6px;
            font-weight: 700;
            font-size: 1rem;
            cursor: pointer;
        }

        button[type="submit"]:disabled {
            opacity: .5;
            cursor: not-allowed;
        }

        #result {
            margin-top: 1rem;
            line-height: 1.55;
            font-weight: 600;
        }

        .hp {
            position: absolute;
            left: -10000px;
        }

        @media (max-width: 620px) {
            .card {
                margin: 1rem;
                padding: 1.4rem;
            }
        }
		
		.contact-permission {
			margin-top: 1rem;
			padding-top: 1rem;
			border-top: 1px solid #dfe3e7;
		}

		.contact-permission label {
			display: flex;
			align-items: flex-start;
			gap: .65rem;
			cursor: pointer;
		}

		.contact-permission input[type="checkbox"] {
			width: auto;
			margin-top: .25rem;
			flex: 0 0 auto;
		}
    </style>
</head>

<body>

<main class="card">

    <h1>Подай сигнал</h1>

    <p class="intro">
        Подайте сигнал бързо и лесно само със снимка.
        Можете да използвате снимка от телефон, таблет, дрон
        или друго устройство, което записва GPS местоположението
        и датата и часа на заснемане в снимката.
    </p>

    <div class="important">
        <strong>Важно:</strong>
        Снимката трябва да съдържа GPS координати и дата и час
        на заснемане в оригиналните EXIF данни.
    </div>

    <form
        id="signal-form"
        method="post"
        action="submit.php"
        enctype="multipart/form-data"
    >

        <input
            type="hidden"
            name="_csrf"
            value="<?= htmlspecialchars(
                $csrf,
                ENT_QUOTES,
                'UTF-8'
            ) ?>"
        >

        <!-- Honeypot -->
        <label
            class="hp"
            aria-hidden="true"
        >
            Website
            <input
                name="website"
                tabindex="-1"
                autocomplete="off"
            >
        </label>

        <!-- Photo -->
        <span class="field-title">
            Изберете снимка за подаване на сигнала
        </span>

        <label class="file-button">
            Избери снимка
            <input
                id="photo"
                name="photo"
                type="file"
                accept="image/jpeg, text/plain"
                required
            >
        </label>

        <div class="requirements">
            JPEG/JPG снимка с GPS координати и дата и час
            в оригиналните EXIF данни.
            Максимален размер: 20 MB.
            Максимална резолюция: 50 MP.
        </div>

        <img
            id="preview"
            alt="Преглед на избраната снимка"
        >

        <p
            id="file-info"
            aria-live="polite"
        >
            Не е избрана снимка.
        </p>
		
		<div
			id="gps-help"
			class="gps-help"
		>
			Ако използвате телефон с Android, е възможно
			приложението за избор на снимки да е премахнало
			GPS информацията от снимката.
			<a href="android-gps-help.php">
				Вижте помощ за Android при липсващи GPS данни →
			</a>
		</div>

        <!-- Email -->
        <label
            class="field-title"
            for="email"
        >
            Въведете валиден e-mail адрес за потвърждение
            на сигнала
        </label>

        <input
            id="email"
            name="email"
            type="email"
            maxlength="254"
            required
            autocomplete="email"
        >

        <div class="email-help">
            На този адрес ще получите линк за потвърждение.
            Без потвърждение сигналът няма да бъде изпратен
            до съответната институция.
        </div>

        <!-- Privacy / information acknowledgement -->
        <div class="privacy">

            <label for="privacy_acknowledged">

                <input
                    id="privacy_acknowledged"
                    name="privacy_acknowledged"
                    type="checkbox"
                    value="1"
                >

                <span>
                    Запознах се с начина на работа на системата
                    и с информацията за обработването на лични
                    данни.
                </span>

            </label>

		<div class="contact-permission">

			<label for="reporter_contact_allowed">

				<input
					id="reporter_contact_allowed"
					name="reporter_contact_allowed"
					type="checkbox"
					value="1"
				>

				<span>
					Разрешавам при необходимост от допълнителна
					информация относно сигнала да се свържат с мен
					на посочения e-mail адрес.
				</span>

			</label>

		</div>

            <div class="privacy-links">
                <a
                    href="how-it-works.php"
                    target="_blank"
                    rel="noopener"
                >
                    Как работи системата
                </a>

                &nbsp;·&nbsp;

                <a
                    href="privacy.php"
                    target="_blank"
                    rel="noopener"
                >
                    Поверителност и защита на личните данни
                </a>
            </div>

        </div>

        <button
            id="submit"
            type="submit"
            disabled
        >
            ПОДАЙ СИГНАЛ
        </button>

    </form>

    <p
        id="result"
        aria-live="polite"
    ></p>

</main>

<script>
const form = document.querySelector('#signal-form');
const photoInput = document.querySelector('#photo');
const emailInput = document.querySelector('#email');
const privacyInput =
    document.querySelector('#privacy_acknowledged');
const submitButton =
    document.querySelector('#submit');
const fileInfo =
    document.querySelector('#file-info');
const gpsHelp =
    document.querySelector('#gps-help');
const result =
    document.querySelector('#result');
const preview =
    document.querySelector('#preview');

let photoOK = false;

/*
 * The submit button is enabled only when:
 *
 * 1. the photo passed server-side inspection;
 * 2. the email is valid according to the browser;
 * 3. the privacy/information acknowledgement is checked.
 *
 * submit.php performs the same privacy check server-side.
 */
function refreshSubmitButton() {
    submitButton.disabled = !(
        photoOK
        && emailInput.validity.valid
        && privacyInput.checked
    );
}

emailInput.addEventListener(
    'input',
    refreshSubmitButton
);

privacyInput.addEventListener(
    'change',
    refreshSubmitButton
);

photoInput.addEventListener(
    'change',
    async () => {

        photoOK = false;
        refreshSubmitButton();

        result.textContent = '';
        result.className = '';
		
		gpsHelp.style.display = 'none';

        const file = photoInput.files[0];

        if (!file) {
            fileInfo.textContent =
                'Не е избрана снимка.';
            fileInfo.className = '';
            preview.style.display = 'none';
            return;
        }

        /*
         * Show local preview.
         */
        if (preview.src) {
            URL.revokeObjectURL(preview.src);
        }

        preview.src = URL.createObjectURL(file);
        preview.style.display = 'block';

        fileInfo.textContent =
            `${file.name} — ` +
            `${(file.size / 1024 / 1024).toFixed(2)} MB. ` +
            `Проверка...`;

        fileInfo.className = '';

        /*
         * Server-side photo inspection.
         */
        const data = new FormData();

        data.append(
            '_csrf',
            form._csrf.value
        );

        data.append(
            'photo',
            file
        );

        try {

            const response = await fetch(
                'validate-photo.php',
                {
                    method: 'POST',
                    body: data
                }
            );

            const answer = await response.json();

            photoOK = !!answer.valid;

			fileInfo.textContent =
				answer.message || (
					photoOK
						? 'Снимката е валидна.'
						: 'Снимката не е подходяща.'
				);

			fileInfo.className =
				photoOK
					? 'valid'
					: 'invalid';

			/*
			 * Show Android help only when the server reports
			 * missing or invalid EXIF GPS metadata.
			 */
			if (
				!photoOK
				&& answer.message ===
					'В снимката липсват валидни GPS координати.'
			) {
				gpsHelp.style.display = 'block';
			}

        } catch (error) {

            photoOK = false;

            fileInfo.textContent =
                'Проверката на снимката не беше успешна.';

            fileInfo.className = 'invalid';
        }

        refreshSubmitButton();
    }
);

form.addEventListener(
    'submit',
    async (event) => {

        event.preventDefault();

        refreshSubmitButton();

        if (submitButton.disabled) {
            return;
        }

        submitButton.disabled = true;

        result.textContent = '';
        result.className = '';

        try {

            const response = await fetch(
                form.action,
                {
                    method: 'POST',
                    body: new FormData(form)
                }
            );

            const answer =
                await response.json();

            if (answer.ok) {

                result.className = 'valid';

                result.textContent =
                    answer.message
                    + (
                        answer.reference
                            ? ` Номер на сигнала: ${answer.reference}`
                            : ''
                    );

                /*
                 * The signal has been successfully submitted.
                 * Prevent accidental duplicate submission.
                 */
                form.reset();

                photoOK = false;

                preview.style.display = 'none';
                preview.removeAttribute('src');

                fileInfo.textContent =
                    'Не е избрана снимка.';

                fileInfo.className = '';

                submitButton.disabled = true;

            } else {

                result.className = 'invalid';

                result.textContent =
                    answer.message
                    || 'Сигналът не можа да бъде приет.';

                refreshSubmitButton();
            }

        } catch (error) {

            result.className = 'invalid';

            result.textContent =
                'Възникна техническа грешка при подаването на сигнала. Моля, опитайте отново.';

            refreshSubmitButton();
        }
    }
);

refreshSubmitButton();
</script>

</body>
</html>