# Shop

Details for the inventory of `leitvia-migrate` when the site runs WooCommerce.

## Product attributes

The global attributes keep the names the old product pages show. A name in `leitvia/list-product-attributes` that differs (an attribute set up again as `Color` where the old shop said `Farbe`) gets the old one with `leitvia/update-product-attribute`: `name`, `values` and `order` as the old pages list them, with the `package` key. A new name changes every product with the attribute and its filters: list old and new names at checkpoint **Open items** and write them only after the person agreed. A missing attribute or value is for the person.

## Reviews

Reviews keep their stars. Per product, compare the old product page with `leitvia/list-product-reviews` (`product`, 20 per page up to `pages`): the tab "Reviews (N)" is `total`, the line "Rated 4.50 out of 5 based on 2 customer ratings" under the title counts the reviews with stars, `total` minus `unrated_total`. Pair each review by author and date. One that came over without its stars gets the rating of the old page with `leitvia/update-product-review`: `review`, `rating`, the review `hash` as `base_hash` and the `package` key. Afterwards the new product page shows the same line under the title. A missing review, a different rating, the verified owner mark and held reviews are for the person: list them in the report.
