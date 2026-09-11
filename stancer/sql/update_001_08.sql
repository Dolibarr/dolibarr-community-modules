--
-- Remember on the payment row whether it only covers a deposit.
--
-- The "30% on order" setting (STANCER_CB_ORDER_PARTIAL_PAY, and its proposal
-- counterpart STANCER_CB_PROPAL_PARTIAL_PAY) used to be carried to the return
-- page by $_SESSION["partialPayment"] alone. That session does not exist when
-- the customer opens the payment link from the email sent by
-- STANCER_AUTO_MAIL_ORDER_CB: paymentback.php then fell back to a full payment
-- and issued a full invoice for a deposit that was really charged.
--
-- Mirror of the same column added to sql/llx_stancer_stancer_payments.sql for
-- new installations.
--

ALTER TABLE `llx_stancer_stancer_payments` ADD COLUMN partial_payment integer DEFAULT 0;
