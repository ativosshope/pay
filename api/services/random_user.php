<?php
function getRandomBrazilianUser() {
    $ch = curl_init('https://randomuser.me/api/?nat=br');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 5
    ]);
    
    $response = curl_exec($ch);
    curl_close($ch);
    
    $data = json_decode($response, true);
    
    if (!empty($data['results'][0])) {
        $user = $data['results'][0];
        return [
            'firstName' => $user['name']['first'] ?? 'Maria',
            'lastName' => $user['name']['last'] ?? 'Silva',
            'email' => $user['email'] ?? 'user@email.com',
            'phone' => $user['phone'] ?? '11999999999',
            'city' => $user['location']['city'] ?? 'São Paulo',
            'state' => $user['location']['state'] ?? 'SP',
            'postcode' => $user['location']['postcode'] ?? '01000000'
        ];
    }
    
    return [
        'firstName' => 'Maria',
        'lastName' => 'Silva',
        'email' => 'cliente' . rand(1000, 9999) . '@email.com',
        'phone' => '11' . rand(900000000, 999999999),
        'city' => 'São Paulo',
        'state' => 'SP',
        'postcode' => '01000000'
    ];
}