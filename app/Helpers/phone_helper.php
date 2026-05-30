<?php

if (!function_exists('clean_phone')) {
    /**
     * Nettoyer un numéro de téléphone pour ne garder que les chiffres.
     * Enlève les espaces, les parenthèses, les tirets et le signe '+'.
     *
     * @param string $phone
     * @return string
     */
    function clean_phone(string $phone): string
    {
        return preg_replace('/[^0-9]/', '', $phone);
    }
}

if (!function_exists('format_to_international')) {
    /**
     * Convertit un numéro de téléphone au format international strict sans le '+' (ex: 2376XXXXXXXX).
     * Gère automatiquement l'ajout du préfixe par défaut si le numéro est saisi au format local.
     *
     * @param string $phone Le numéro brut saisi à la caisse
     * @param string $defaultCountryCode Le code pays par défaut (ex: '237' pour le Cameroun)
     * @return string
     */
    function format_to_international(string $phone, string $defaultCountryCode = '237'): string
    {
        $digits = clean_phone($phone);

        // Si le numéro commence par '00', on remplace par rien (ex: 002376... devient 2376...)
        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }

        // Si le numéro est au format local camerounais (9 chiffres commençant par 6, 2, ou 3)
        if (strlen($digits) === 9 && in_array($digits[0], ['6', '2', '3'])) {
            $digits = $defaultCountryCode . $digits;
        }

        return $digits;
    }
}

if (!function_exists('detect_cameroon_operator')) {
    /**
     * Détecter dynamiquement l'opérateur mobile camerounais à partir du numéro de téléphone.
     * Très utile pour l'aiguillage automatique des flux Mobile Money (MTN MoMo vs Orange Money).
     *
     * @param string $phone
     * @return string 'mtn'|'orange'|'camtel'|'yooome'|'unknown'
     */
    function detect_cameroon_operator(string $phone): string
    {
        // On normalise d'abord au format international Cameroun
        $international = format_to_international($phone, '237');

        // On extrait le numéro local à 9 chiffres (on retire le '237')
        if (str_starts_with($international, '237') && strlen($international) === 12) {
            $local = substr($international, 3);
        } else {
            $local = $international;
        }

        if (strlen($local) !== 9) {
            return 'unknown';
        }

        // Extraction des deux premiers chiffres (les préfixes de routage)
        $prefix = substr($local, 0, 2);

        // 1. Liste des préfixes MTN Cameroon
        $mtnPrefixes = ['650', '651', '652', '653', '654', '67', '680', '681', '682', '683'];
        // 2. Liste des préfixes Orange Cameroun
        $orangePrefixes = ['655', '656', '657', '658', '659', '69'];
        // 3. Liste des préfixes Camtel (Mobile/Fixe)
        $camtelPrefixes = ['22', '23', '24', '620', '621'];

        // Vérification par correspondance
        if (in_array($prefix, $mtnPrefixes) || in_array(substr($local, 0, 3), $mtnPrefixes)) {
            return 'mtn';
        }

        if (in_array($prefix, $orangePrefixes) || in_array(substr($local, 0, 3), $orangePrefixes)) {
            return 'orange';
        }

        if (in_array($prefix, $camtelPrefixes) || in_array(substr($local, 0, 3), $camtelPrefixes)) {
            return 'camtel';
        }

        return 'unknown';
    }
}

if (!function_exists('validate_phone_structure')) {
    /**
     * Valider si la structure globale du numéro correspond aux standards autorisés.
     *
     * @param string $phone
     * @param string $countryCode
     * @return bool
     */
    function validate_phone_structure(string $phone, string $countryCode = '237'): bool
    {
        $international = format_to_international($phone, $countryCode);

        // Pour le Cameroun : 12 chiffres au total (237 + 9 chiffres)
        if ($countryCode === '237') {
            return preg_match('/^2376[0-9]{8}$|^2372[0-9]{8}$/', $international) === 1;
        }

        // Règle générique par défaut pour la sous-région (entre 11 et 14 chiffres)
        return preg_match('/^[0-9]{11,14}$/', $international) === 1;
    }
}
