<?php

class TranslationService
{
    public static function supportedLanguages(): array
    {
        try {
            $rows = Database::connection()
                ->query('SELECT code, name, native_name, direction, is_default, supports_ui, supports_translation, supports_stt, supports_tts, quality_status FROM supported_languages WHERE is_active=1 ORDER BY is_default DESC, name')
                ->fetchAll();
            if ($rows) {
                return array_map(fn ($row) => [
                    'code' => $row['code'],
                    'name' => $row['name'],
                    'native_name' => $row['native_name'],
                    'direction' => $row['direction'],
                    'is_default' => (bool)$row['is_default'],
                    'supports_ui' => (bool)$row['supports_ui'],
                    'supports_translation' => (bool)$row['supports_translation'],
                    'supports_stt' => (bool)$row['supports_stt'],
                    'supports_tts' => (bool)$row['supports_tts'],
                    'quality_status' => $row['quality_status'],
                ], $rows);
            }
        } catch (Throwable) {
        }

        return [
            ['code' => 'en', 'name' => 'English', 'native_name' => 'English', 'direction' => 'ltr', 'is_default' => true, 'supports_ui' => true, 'supports_translation' => true, 'supports_stt' => true, 'supports_tts' => true, 'quality_status' => 'production'],
            ['code' => 'fr', 'name' => 'French', 'native_name' => 'Francais', 'direction' => 'ltr', 'is_default' => false, 'supports_ui' => true, 'supports_translation' => true, 'supports_stt' => false, 'supports_tts' => false, 'quality_status' => 'beta'],
            ['code' => 'rw', 'name' => 'Kinyarwanda', 'native_name' => 'Ikinyarwanda', 'direction' => 'ltr', 'is_default' => false, 'supports_ui' => true, 'supports_translation' => true, 'supports_stt' => false, 'supports_tts' => false, 'quality_status' => 'beta'],
            ['code' => 'sw', 'name' => 'Kiswahili', 'native_name' => 'Kiswahili', 'direction' => 'ltr', 'is_default' => false, 'supports_ui' => true, 'supports_translation' => true, 'supports_stt' => false, 'supports_tts' => false, 'quality_status' => 'beta'],
        ];
    }

    public static function translate(string $text, string $targetLanguage, string $sourceLanguage = 'en', ?array $user = null): array
    {
        $text = trim($text);
        $sourceLanguage = strtolower(trim($sourceLanguage ?: 'en'));
        $targetLanguage = strtolower(trim($targetLanguage));
        if ($text === '') {
            Response::error('Text is required for translation', 422);
        }
        if (!self::languageExists($sourceLanguage) || !self::languageExists($targetLanguage)) {
            Response::error('Unsupported source or target language', 422);
        }
        if ($sourceLanguage === $targetLanguage) {
            return self::recordRequest($text, $text, $sourceLanguage, $targetLanguage, 'none', 100, 'translated', $user);
        }

        $memory = self::lookupMemory($text, $sourceLanguage, $targetLanguage);
        if ($memory) {
            return self::recordRequest($text, $memory['translated_text'], $sourceLanguage, $targetLanguage, $memory['provider'], 98, 'translated', $user);
        }

        $modelResult = self::translateWithModel($text, $sourceLanguage, $targetLanguage);
        if ($modelResult) {
            return self::recordRequest(
                $text,
                $modelResult['translated_text'],
                $sourceLanguage,
                $targetLanguage,
                $modelResult['provider'],
                85,
                'needs_review',
                $user,
                $modelResult['model'] ?? null
            );
        }

        $dictionary = self::dictionary();
        $key = $sourceLanguage . ':' . $targetLanguage;
        $translated = $text;
        $matched = 0;
        if (isset($dictionary[$key])) {
            uksort($dictionary[$key], fn ($a, $b) => strlen($b) <=> strlen($a));
            foreach ($dictionary[$key] as $source => $target) {
                $count = 0;
                $translated = str_ireplace($source, $target, $translated, $count);
                $matched += $count;
            }
        }

        $quality = $matched > 0 ? 'partial' : 'needs_review';
        $confidence = $matched > 0 ? 70 : 0;
        self::recordFallback(
            'translation',
            'nllb',
            'facebook/nllb-200-distilled-600M',
            'internal_dictionary',
            'local-phrase-memory',
            'NLLB service unavailable or returned no usable translation',
            $user,
            ['source_language' => $sourceLanguage, 'target_language' => $targetLanguage, 'matched_terms' => $matched]
        );
        return self::recordRequest($text, $translated, $sourceLanguage, $targetLanguage, 'internal_dictionary', $confidence, $quality, $user);
    }

    public static function translateBookText(string $text, string $targetLanguage, string $sourceLanguage = 'en', ?array $user = null): array
    {
        $text = trim($text);
        if ($text === '') {
            Response::error('Book text is empty or could not be extracted', 422);
        }

        $maxChars = 20000;
        $truncated = mb_strlen($text) > $maxChars;
        $textForTranslation = mb_substr($text, 0, $maxChars);
        $result = self::translate($textForTranslation, $targetLanguage, $sourceLanguage, $user);
        $result['characters_translated'] = mb_strlen($textForTranslation);
        $result['source_truncated'] = $truncated;
        $result['note'] = $truncated
            ? 'Book text exceeded the synchronous translation limit. Use background jobs for full production-scale book translation.'
            : 'Book text translated synchronously.';
        return $result;
    }

    public static function health(): array
    {
        $modelHealth = self::modelHealth();
        return [
            'status' => 'ready',
            'supported_languages' => array_column(self::supportedLanguages(), 'code'),
            'provider' => ($modelHealth['status'] ?? '') === 'ready' ? 'nllb' : 'internal_dictionary',
            'model_health' => $modelHealth,
            'note' => 'Pretrained NLLB is used when available. High-stakes production translation should still use review workflows, especially for Kinyarwanda education content.',
        ];
    }

    private static function languageExists(string $code): bool
    {
        foreach (self::supportedLanguages() as $language) {
            if ($language['code'] === $code) {
                return true;
            }
        }
        return false;
    }

    private static function lookupMemory(string $text, string $sourceLanguage, string $targetLanguage): ?array
    {
        try {
            $stmt = Database::connection()->prepare(
                'SELECT translated_text, provider, quality_status FROM translation_memory
                 WHERE source_language=:source_language AND target_language=:target_language
                   AND source_text=:source_text AND status="active"
                 ORDER BY quality_status="approved" DESC, updated_at DESC LIMIT 1'
            );
            $stmt->execute([
                ':source_language' => $sourceLanguage,
                ':target_language' => $targetLanguage,
                ':source_text' => $text,
            ]);
            $row = $stmt->fetch();
            return $row ?: null;
        } catch (Throwable) {
            return null;
        }
    }

    private static function translateWithModel(string $text, string $sourceLanguage, string $targetLanguage): ?array
    {
        $config = self::config();
        $url = (string)($config['nllb_translation_url'] ?? '');
        if ($url === '' || !function_exists('curl_init')) {
            return null;
        }
        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 180,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode([
                'text' => $text,
                'source_language' => $sourceLanguage,
                'target_language' => $targetLanguage,
            ]),
        ]);
        $raw = curl_exec($curl);
        $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);
        if ($raw === false || $status < 200 || $status >= 300) {
            return null;
        }
        $data = json_decode($raw, true);
        if (!is_array($data) || !($data['success'] ?? false) || trim((string)($data['translated_text'] ?? '')) === '') {
            return null;
        }
        return [
            'translated_text' => (string)$data['translated_text'],
            'provider' => (string)($data['provider'] ?? 'nllb'),
            'model' => (string)($data['model'] ?? 'facebook/nllb-200-distilled-600M'),
        ];
    }

    private static function modelHealth(): array
    {
        $config = self::config();
        $url = (string)($config['nllb_translation_health_url'] ?? '');
        if ($url === '' || !function_exists('curl_init')) {
            return ['status' => 'unavailable', 'provider' => 'nllb'];
        }
        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_CONNECTTIMEOUT => 2,
        ]);
        $raw = curl_exec($curl);
        $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);
        if ($raw === false || $status < 200 || $status >= 300) {
            return ['status' => 'offline', 'provider' => 'nllb'];
        }
        $data = json_decode($raw, true);
        return is_array($data) ? $data : ['status' => 'invalid_response', 'provider' => 'nllb'];
    }

    private static function recordRequest(string $sourceText, string $translatedText, string $sourceLanguage, string $targetLanguage, string $provider, float $confidence, string $quality, ?array $user, ?string $modelName = null): array
    {
        $institutionId = class_exists('TenantService') ? TenantService::institutionIdFor($user) : null;
        $requestId = null;
        try {
            $stmt = Database::connection()->prepare(
                'INSERT INTO translation_requests
                 (institution_id, user_id, source_language, target_language, source_text, translated_text, provider, confidence, quality_status, status)
                 VALUES (:institution_id, :user_id, :source_language, :target_language, :source_text, :translated_text, :provider, :confidence, :quality_status, "completed")'
            );
            $stmt->execute([
                ':institution_id' => $institutionId,
                ':user_id' => $user['id'] ?? null,
                ':source_language' => $sourceLanguage,
                ':target_language' => $targetLanguage,
                ':source_text' => $sourceText,
                ':translated_text' => $translatedText,
                ':provider' => $provider,
                ':confidence' => $confidence,
                ':quality_status' => $quality,
            ]);
            $requestId = (int)Database::connection()->lastInsertId();

            Database::connection()->prepare(
                'INSERT INTO ai_usage_logs
                 (institution_id, user_id, service_name, provider, model_name, input_units, output_units, status, metadata)
                 VALUES (:institution_id, :user_id, "translation", :provider, :model_name, :input_units, :output_units, "success", :metadata)'
            )->execute([
                ':institution_id' => $institutionId,
                ':user_id' => $user['id'] ?? null,
                ':provider' => $provider,
                ':model_name' => $modelName ?: 'internal-translation-memory',
                ':input_units' => mb_strlen($sourceText),
                ':output_units' => mb_strlen($translatedText),
                ':metadata' => json_encode(['source_language' => $sourceLanguage, 'target_language' => $targetLanguage, 'quality_status' => $quality]),
            ]);
        } catch (Throwable) {
        }

        return [
            'id' => $requestId,
            'source_language' => $sourceLanguage,
            'target_language' => $targetLanguage,
            'source_text' => $sourceText,
            'translated_text' => $translatedText,
            'provider' => $provider,
            'model' => $modelName,
            'confidence' => $confidence,
            'quality_status' => $quality,
            'institution_id' => $institutionId,
        ];
    }

    private static function recordFallback(string $task, string $primaryProvider, ?string $primaryModel, string $fallbackProvider, ?string $fallbackModel, string $reason, ?array $user, array $metadata = []): void
    {
        try {
            Database::connection()->prepare(
                'INSERT INTO model_fallback_logs
                 (institution_id, user_id, task, primary_provider, primary_model, fallback_provider, fallback_model, reason, status, metadata)
                 VALUES (:institution_id, :user_id, :task, :primary_provider, :primary_model, :fallback_provider, :fallback_model, :reason, "used", :metadata)'
            )->execute([
                ':institution_id' => class_exists('TenantService') ? TenantService::institutionIdFor($user) : null,
                ':user_id' => $user['id'] ?? null,
                ':task' => $task,
                ':primary_provider' => $primaryProvider,
                ':primary_model' => $primaryModel,
                ':fallback_provider' => $fallbackProvider,
                ':fallback_model' => $fallbackModel,
                ':reason' => $reason,
                ':metadata' => json_encode($metadata, JSON_UNESCAPED_UNICODE),
            ]);
        } catch (Throwable) {
        }
    }

    private static function dictionary(): array
    {
        return [
            'en:fr' => [
                'Dashboard' => 'Tableau de bord',
                'Search Books' => 'Rechercher des livres',
                'Upload Book' => 'Televerser un livre',
                'Submit Book' => 'Soumettre un livre',
                'My Borrowed Books' => 'Mes livres empruntes',
                'Reading Progress' => 'Progression de lecture',
                'Favorites' => 'Favoris',
                'Bookmarks' => 'Signets',
                'Recommendations' => 'Recommandations',
                'Notifications' => 'Notifications',
                'Books' => 'Livres',
                'Users' => 'Utilisateurs',
                'Analytics' => 'Analytique',
                'Reports' => 'Rapports',
                'Settings' => 'Parametres',
                'Library' => 'Bibliotheque',
            ],
            'en:rw' => [
                'Dashboard' => "Ahabanza h'ibikorwa",
                'Search Books' => 'Shakisha ibitabo',
                'Upload Book' => 'Ohereza igitabo',
                'Submit Book' => 'Tanga igitabo',
                'My Borrowed Books' => 'Ibitabo natije',
                'Reading Progress' => 'Aho ngeze nsoma',
                'Favorites' => 'Ibyo nkunda',
                'Bookmarks' => 'Utumenyetso two gusoma',
                'Recommendations' => 'Ibyifuzo',
                'Notifications' => 'Amatangazo',
                'Books' => 'Ibitabo',
                'Users' => 'Abakoresha',
                'Analytics' => 'Isesengura',
                'Reports' => 'Raporo',
                'Settings' => 'Igenamiterere',
                'Library' => 'Isomero',
            ],
            'en:sw' => [
                'Dashboard' => 'Dashibodi',
                'Search Books' => 'Tafuta vitabu',
                'Upload Book' => 'Pakia kitabu',
                'Submit Book' => 'Wasilisha kitabu',
                'My Borrowed Books' => 'Vitabu nilivyoazima',
                'Reading Progress' => 'Maendeleo ya kusoma',
                'Favorites' => 'Vipendwa',
                'Bookmarks' => 'Alamisho',
                'Recommendations' => 'Mapendekezo',
                'Notifications' => 'Arifa',
                'Books' => 'Vitabu',
                'Users' => 'Watumiaji',
                'Analytics' => 'Takwimu',
                'Reports' => 'Ripoti',
                'Settings' => 'Mipangilio',
                'Library' => 'Maktaba',
            ],
        ];
    }

    private static function config(): array
    {
        static $config = null;
        if ($config === null) {
            $config = require __DIR__ . '/../config/app.php';
        }
        return $config;
    }
}
