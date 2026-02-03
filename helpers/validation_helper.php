<?php
/**
 * Helper de Validação
 * Funções para validar dados de entrada (CPF, telefone, CEP, etc.)
 */

if (!function_exists('validate_cpf')) {
    /**
     * Valida CPF brasileiro com algoritmo de dígitos verificadores
     * @param string $cpf CPF a ser validado (pode conter formatação)
     * @return bool True se válido, False caso contrário
     */
    function validate_cpf($cpf) {
        // Remove caracteres não numéricos
        $cpf = preg_replace('/[^0-9]/', '', $cpf);
        
        // Verifica se tem 11 dígitos
        if (strlen($cpf) != 11) {
            return false;
        }
        
        // Verifica se todos os dígitos são iguais (CPF inválido)
        if (preg_match('/(\d)\1{10}/', $cpf)) {
            return false;
        }
        
        // Valida primeiro dígito verificador
        $sum = 0;
        for ($i = 0; $i < 9; $i++) {
            $sum += intval($cpf[$i]) * (10 - $i);
        }
        $remainder = $sum % 11;
        $digit1 = ($remainder < 2) ? 0 : 11 - $remainder;
        
        if (intval($cpf[9]) != $digit1) {
            return false;
        }
        
        // Valida segundo dígito verificador
        $sum = 0;
        for ($i = 0; $i < 10; $i++) {
            $sum += intval($cpf[$i]) * (11 - $i);
        }
        $remainder = $sum % 11;
        $digit2 = ($remainder < 2) ? 0 : 11 - $remainder;
        
        return intval($cpf[10]) == $digit2;
    }
}

if (!function_exists('validate_phone_br')) {
    /**
     * Valida telefone brasileiro
     * @param string $phone Telefone a ser validado
     * @return bool True se válido, False caso contrário
     */
    function validate_phone_br($phone) {
        // Remove caracteres não numéricos
        $phone = preg_replace('/[^0-9]/', '', $phone);
        
        // Telefone brasileiro deve ter 10 ou 11 dígitos
        // 10 dígitos: (XX) XXXX-XXXX
        // 11 dígitos: (XX) 9XXXX-XXXX (celular com 9)
        if (strlen($phone) < 10 || strlen($phone) > 11) {
            return false;
        }
        
        // Verifica se começa com DDD válido (11-99)
        $ddd = substr($phone, 0, 2);
        if ($ddd < 11 || $ddd > 99) {
            return false;
        }
        
        return true;
    }
}

if (!function_exists('validate_cep')) {
    /**
     * Valida CEP brasileiro
     * @param string $cep CEP a ser validado
     * @return bool True se válido, False caso contrário
     */
    function validate_cep($cep) {
        // Remove caracteres não numéricos
        $cep = preg_replace('/[^0-9]/', '', $cep);
        
        // CEP deve ter exatamente 8 dígitos
        if (strlen($cep) != 8) {
            return false;
        }
        
        // CEP não pode ser 00000000
        if ($cep === '00000000') {
            return false;
        }
        
        return true;
    }
}

if (!function_exists('validate_email')) {
    /**
     * Valida email usando filter_var
     * @param string $email Email a ser validado
     * @return bool True se válido, False caso contrário
     */
    function validate_email($email) {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }
}

if (!function_exists('validate_transaction_amount')) {
    /**
     * Valida valor de transação
     * @param float|string $amount Valor a ser validado
     * @param float $min Valor mínimo (padrão: 0.01)
     * @param float $max Valor máximo (padrão: 100000.00)
     * @return bool True se válido, False caso contrário
     */
    function validate_transaction_amount($amount, $min = 0.01, $max = 100000.00) {
        $amount = floatval($amount);
        
        if ($amount < $min || $amount > $max) {
            return false;
        }
        
        return is_finite($amount) && $amount > 0;
    }
}

if (!function_exists('sanitize_input')) {
    /**
     * Sanitiza input removendo caracteres perigosos
     * @param string $input Input a ser sanitizado
     * @param bool $allow_html Se true, permite HTML (usa strip_tags)
     * @return string Input sanitizado
     */
    function sanitize_input($input, $allow_html = false) {
        if (!is_string($input)) {
            return $input;
        }
        
        // Remove caracteres de controle
        $input = preg_replace('/[\x00-\x1F\x7F]/', '', $input);
        
        // Remove ou escapa HTML
        if (!$allow_html) {
            $input = htmlspecialchars($input, ENT_QUOTES, 'UTF-8');
        } else {
            $input = strip_tags($input);
        }
        
        return trim($input);
    }
}

if (!function_exists('validate_product_ids')) {
    /**
     * Valida array de IDs de produtos (para prevenir SQL injection)
     * @param array $product_ids Array de IDs
     * @param int $max_products Número máximo de produtos (padrão: 10)
     * @return array Array de IDs validados como inteiros
     * @throws Exception Se validação falhar
     */
    function validate_product_ids($product_ids, $max_products = 10) {
        if (!is_array($product_ids)) {
            throw new Exception('IDs de produtos devem ser um array');
        }
        
        if (count($product_ids) > $max_products) {
            throw new Exception("Número máximo de produtos excedido: {$max_products}");
        }
        
        if (count($product_ids) === 0) {
            throw new Exception('Array de produtos não pode estar vazio');
        }
        
        // Converte todos para inteiros e valida
        $validated_ids = [];
        foreach ($product_ids as $id) {
            $int_id = intval($id);
            if ($int_id <= 0) {
                throw new Exception("ID de produto inválido: {$id}");
            }
            $validated_ids[] = $int_id;
        }
        
        // Remove duplicatas
        $validated_ids = array_unique($validated_ids);
        
        return $validated_ids;
    }
}

if (!function_exists('validate_password_strength')) {
    /**
     * Valida força de senha
     * @param string $password Senha a ser validada
     * @return array ['valid' => bool, 'errors' => array]
     */
    function validate_password_strength($password) {
        $errors = [];
        
        if (strlen($password) < 8) {
            $errors[] = 'A senha deve ter pelo menos 8 caracteres';
        }
        
        if (!preg_match('/[A-Z]/', $password)) {
            $errors[] = 'A senha deve conter pelo menos uma letra maiúscula';
        }
        
        if (!preg_match('/[a-z]/', $password)) {
            $errors[] = 'A senha deve conter pelo menos uma letra minúscula';
        }
        
        if (!preg_match('/[0-9]/', $password)) {
            $errors[] = 'A senha deve conter pelo menos um número';
        }
        
        // Opcional: verificar senhas comuns
        $common_passwords = ['password', '12345678', 'qwerty', 'abc123', 'senha123'];
        if (in_array(strtolower($password), $common_passwords)) {
            $errors[] = 'A senha é muito comum. Escolha uma senha mais segura';
        }
        
        return [
            'valid' => empty($errors),
            'errors' => $errors
        ];
    }
}

