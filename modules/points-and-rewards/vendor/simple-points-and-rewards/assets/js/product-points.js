/**
 * Format a numeric value as a price string using WooCommerce currency settings.
 */
function sparFormatPrice(value, rr) {
    var decimals    = typeof rr.decimals !== 'undefined' ? parseInt(rr.decimals, 10) : 2;
    var decimalSep  = rr.decimalSep || '.';
    var thousandSep = rr.thousandSep || ',';
    var symbol      = rr.currencySymbol || '$';
    var pos         = rr.currencyPos || 'left';

    // Round and format the number.
    var fixed   = value.toFixed(decimals);
    var parts   = fixed.split('.');
    var intPart = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, thousandSep);
    var formatted = decimals > 0 ? intPart + decimalSep + parts[1] : intPart;

    switch (pos) {
        case 'left':       return symbol + formatted;
        case 'left_space': return symbol + '\u00a0' + formatted;
        case 'right':      return formatted + symbol;
        case 'right_space':return formatted + '\u00a0' + symbol;
        default:           return symbol + formatted;
    }
}

jQuery(document).ready(function($) {
    var $form = $('.variations_form');
    var $pointsMessage = $('.spar-points-message');
    var originalMessage = $pointsMessage.html();

    if ($form.length === 0 || $pointsMessage.length === 0) {
        return;
    }

    // Hide points block initially on variable products until a variation is selected.
    $pointsMessage.closest('.spar-product-points').hide();

    $form.on('found_variation', function(event, variation) {
        if (!variation) {
            return;
        }

        var price = variation.display_price;
        var regularPrice = variation.display_regular_price || price;

        // Check for mode preference
        if (sparProductPoints.mode === 'total_before_discounts' && variation.display_regular_price) {
             price = variation.display_regular_price;
        }

        // When taxes are excluded from the points calculation but the shop displays
        if (!sparProductPoints.includeTaxes && sparProductPoints.displayPriceInclTax) {
            // Use variation.price which WooCommerce stores as the raw DB price.
            if (typeof variation.price !== 'undefined' && parseFloat(variation.price) > 0) {
                if (sparProductPoints.mode === 'total_before_discounts') {
                    var rawRegular = parseFloat(variation.display_regular_price || variation.display_price || 0);
                    // Approximate excl-tax regular price using same ratio as current price
                    var ratio = (parseFloat(variation.price) > 0 && parseFloat(variation.display_price) > 0)
                        ? parseFloat(variation.price) / parseFloat(variation.display_price)
                        : 1;
                    price = rawRegular * ratio;
                } else {
                    price = parseFloat(variation.price);
                }
            }
        }

        var rate = parseFloat(sparProductPoints.rate);
        var multiplier = parseFloat(sparProductPoints.multiplier);
        
        var basePoints = Math.floor(price * Math.max(0, rate));
        var points = Math.floor(basePoints * Math.max(0, multiplier));

        if (sparProductPoints.conditional) {
            var cond = sparProductPoints.conditional;
            var condMult = parseFloat(cond.multiplier || 1);
            var condMin = parseInt(cond.minPoints || 0, 10) || 0;
            var condMax = parseInt(cond.maxPoints || 0, 10) || 0;
            var condDelta = parseInt(cond.fixedDelta || 0, 10) || 0;
            if (condMult < 0) { condMult = 0; }

            var levelPoints = Math.floor(basePoints * Math.max(0, multiplier));
            points = Math.floor(basePoints * Math.max(0, multiplier) * condMult);

            if (condDelta !== 0) {
                var deltaScaled = condDelta * Math.max(0, multiplier);
                points += (deltaScaled >= 0) ? Math.floor(deltaScaled) : Math.ceil(deltaScaled);
            }

            if (condMin > 0 || condMax > 0) {
                if (condMin > 0 && condMax > 0 && condMax < condMin) {
                    condMax = condMin;
                }
                if (condMin > 0 && points < condMin) {
                    points = condMin;
                }
                if (condMax > 0 && points > condMax) {
                    points = condMax;
                }
            }

            if (isNaN(points) || points < 0) {
                points = levelPoints;
            }
        }
        
        if (points <= 0) {
             $pointsMessage.closest('.spar-product-points').hide();
             return;
        }

        $pointsMessage.closest('.spar-product-points').show();

        var template = sparProductPoints.messageTemplate;
        var isSingular = points === 1;
        var label = (isSingular && sparProductPoints.pointsLabelSingular)
            ? sparProductPoints.pointsLabelSingular
            : sparProductPoints.pointsLabel;
        var labelLower = (isSingular && sparProductPoints.pointsLabelLowerSingular)
            ? sparProductPoints.pointsLabelLowerSingular
            : sparProductPoints.pointsLabelLower;
        
        // Format points (icon + prefix + number)
        var pointsFormatted = (sparProductPoints.pointsIconProminent || '') + (sparProductPoints.pointsPrefix || '') + points.toLocaleString();

        // Calculate monetary value of points using the Points Discount checkout rate.
        var pointsValueFormatted = '';
        var rr = sparProductPoints.redeemRate;
        if (rr && rr.enabled && rr.points > 0 && rr.amount > 0) {
            var monetaryValue = (points * rr.amount) / rr.points;
            pointsValueFormatted = sparFormatPrice(monetaryValue, rr);
        }
        
        var message = template.replace('{points}', pointsFormatted)
                              .replace('{points_label}', label)
                              .replace('{points_label_lower}', labelLower)
                              .replace('{points_value}', pointsValueFormatted);
        
        $pointsMessage.html(message);
    });

    $form.on('reset_data', function() {
        $pointsMessage.closest('.spar-product-points').hide();
    });
});
