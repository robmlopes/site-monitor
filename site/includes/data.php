<?php
function produtos()
{
    return array(
        1 => array('nome' => 'Café especial 250g', 'preco' => 39.90, 'categoria' => 'Mercearia'),
        2 => array('nome' => 'Caneca de cerâmica', 'preco' => 49.00, 'categoria' => 'Casa'),
        3 => array('nome' => 'Moedor manual', 'preco' => 189.90, 'categoria' => 'Utensílios'),
        4 => array('nome' => 'Filtro de papel (100 un.)', 'preco' => 14.50, 'categoria' => 'Mercearia'),
        5 => array('nome' => 'Chaleira bico de ganso', 'preco' => 259.00, 'categoria' => 'Utensílios'),
        6 => array('nome' => 'Balança digital', 'preco' => 129.90, 'categoria' => 'Utensílios'),
    );
}

function preco($v)
{
    return 'R$ ' . number_format($v, 2, ',', '.');
}
