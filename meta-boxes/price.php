<p>
	<label for="deal_base_price"><strong><?php self::_e('Price'); ?>:</strong></label>
	&nbsp;
	<?php gb_currency_symbol();  ?><input id="deal_base_price" type="text" size="5" value="<?php echo $price; ?>" name="deal_base_price" />
</p>