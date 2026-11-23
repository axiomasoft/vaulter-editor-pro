<?php

declare(strict_types=1);

return [
    'exchange_job_not_found' => 'Запрошенное задание обмена документом не найдено.',
    'exchange_idempotency_conflict' => 'Задание обмена с таким request_key уже зарегистрировано с другими параметрами.',
    'exchange_invalid_command' => 'Некорректные параметры команды обмена документом.',
    'exchange_unsafe_archive' => 'Архив DOCX не прошёл предварительную проверку безопасности.',
    'exchange_zip_limit_exceeded' => 'Архив DOCX превысил допустимые лимиты размера, числа файлов или степени сжатия.',
    'exchange_profile_blocked' => 'Выбранный профиль совместимости DOCX заблокировал операцию обмена.',
    'exchange_job_not_ready' => 'Задание обмена ещё не завершило подготовку результата.',
    'exchange_stale_preview' => 'Документ изменился после построения предпросмотра; пересоздайте задание перед применением.',
    'exchange_already_applied' => 'Это задание импорта уже применено с другим apply_command_id.',
    'exchange_source_unavailable' => 'Исходный Media blob или ревизия документа недоступны.',
    'exchange_runtime_failed' => 'Ошибка среды выполнения конвертации DOCX.',
    'exchange_lease_lost' => 'Истекла аренда или потерян fencing-токен воркера при фиксации результата.',
    'exchange_format_disabled' => 'Указанный формат обмена отключён.',
    'exchange_unsupported_document' => 'Тип или состояние документа не поддерживают обмен DOCX.',
];
