<?php
    $c = new Content();
    $c->db->query("UPDATE `$c->table` SET `koken_cart_data` = REPLACE(koken_cart_data, '\"price\":', '\"digital_price\":') WHERE `koken_cart_data` NOT LIKE '%variant%'");

    $p = new Plugin();
    $p->db->query("UPDATE `$p->table` SET `data` = REPLACE(data, 's:16:\"koken_cart_price\";', 's:24:\"koken_cart_digital_price\";') WHERE `path` LIKE 'cart-%'");