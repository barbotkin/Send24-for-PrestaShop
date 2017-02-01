CREATE TABLE IF NOT EXISTS `PREFIX_send24order_value` (
  `id_order` int(11) NOT NULL AUTO_INCREMENT,
  `order_number` int(11) NOT NULL,
  `link_to_pdf` text NOT NULL,
  `link_to_doc` text NOT NULL,
  `link_to_zpl` text NOT NULL,
  `link_to_epl` text NOT NULL,
  `track` text NOT NULL,
  `date_add` datetime NOT NULL,
  PRIMARY KEY (`id_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 AUTO_INCREMENT=1 ;