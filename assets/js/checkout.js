/**
 * Wallet Checkout JavaScript
 * Fixed: No loop on re-render, correct block target, persist state on refresh
 */

(function ($) {
  "use strict";

  var walletPartialPayment = {
    isProcessing: false,
    // وضعیت واقعی که کاربر انتخاب کرده - از DOM اولیه خوانده میشود
    userSelectedState: false,

    init: function () {
      // وضعیت اولیه را از DOM بخوان (PHP آن را از session رندر کرده)
      this.userSelectedState = $("#use_wallet_partial").is(":checked");
      this.bindEvents();
    },

    bindEvents: function () {
      var self = this;

      // event delegation - یک بار bind، برای همیشه کار میکنه
      $(document).on("change.wallet", "#use_wallet_partial", function () {
        // اگر داریم پردازش میکنیم، این change از re-render است نه کاربر
        if (self.isProcessing) return;

        var isChecked = $(this).is(":checked");

        // اگر وضعیت DOM با وضعیت کاربر یکی است، این change از re-render است
        if (isChecked === self.userSelectedState) return;

        self.userSelectedState = isChecked;

        if (isChecked) {
          self.applyPartialPayment();
        } else {
          self.removePartialPayment();
        }
      });

      // بعد از updated_checkout وضعیت اولیه را دوباره از DOM بخوان
      $(document.body).on("updated_checkout", function () {
        self.isProcessing = false;
        self.unblockReview();
        // DOM آپدیت شده، وضعیت checkbox را دوباره sync کن
        self.userSelectedState = $("#use_wallet_partial").is(":checked");
      });
    },

    applyPartialPayment: function () {
      var self = this;

      self.isProcessing = true;
      self.blockReview();

      $.ajax({
        url: walletCheckout.ajax_url,
        type: "POST",
        data: {
          action: "apply_wallet_partial_payment",
          nonce: walletCheckout.nonce,
        },
        success: function (response) {
          if (response.success) {
            // self.showMessage(response.data.message || "پرداخت جزئی اعمال شد", "success");
            $("body").trigger("update_checkout");
          } else {
            self.showMessage((response.data && response.data.message) || "خطا در اعمال پرداخت جزئی", "error");
            self.userSelectedState = false;
            $("#use_wallet_partial").prop("checked", false);
            self.isProcessing = false;
            self.unblockReview();
          }
        },
        error: function () {
          self.showMessage("خطا در برقراری ارتباط با سرور", "error");
          self.userSelectedState = false;
          $("#use_wallet_partial").prop("checked", false);
          self.isProcessing = false;
          self.unblockReview();
        },
      });
    },

    removePartialPayment: function () {
      var self = this;

      self.isProcessing = true;
      self.blockReview();

      $.ajax({
        url: walletCheckout.ajax_url,
        type: "POST",
        data: {
          action: "remove_wallet_partial_payment",
          nonce: walletCheckout.nonce,
        },
        success: function () {
          $("body").trigger("update_checkout");
        },
        error: function () {
          self.showMessage("خطا در برقراری ارتباط با سرور", "error");
          self.userSelectedState = true;
          $("#use_wallet_partial").prop("checked", true);
          self.isProcessing = false;
          self.unblockReview();
        },
      });
    },

    blockReview: function () {
      $(".woocommerce-checkout-review-order").block({
        message: null,
        overlayCSS: {
          background: "#fff",
          opacity: 0.6,
        },
      });
    },

    unblockReview: function () {
      $(".woocommerce-checkout-review-order").unblock();
    },

    showMessage: function (message, type) {
      $(".wallet-temp-message").remove();

      var cssClass = type === "error" ? "woocommerce-error" : "woocommerce-info";
      var html = '<div class="' + cssClass + ' wallet-temp-message">' + message + "</div>";

      $(".woocommerce-notices-wrapper").first().prepend(html);

      setTimeout(function () {
        $(".wallet-temp-message").fadeOut(300, function () {
          $(this).remove();
        });
      }, 4000);
    },
  };

  walletPartialPayment.init();
})(jQuery);
