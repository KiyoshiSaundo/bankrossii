<? if ($params['ERROR']) { ?>
	<p><?=$params['ERROR']?></p>
<? } else if ($params['LINK']) { ?>
	<p>Сумма к оплате: <b><?=number_format($params['SUMM'], 0, '.', ' ')?></b> руб.</p>
	<p><a class="btn btn-primary" href="<?=$params['LINK']?>">Оплатить</a></p>
<? } ?>