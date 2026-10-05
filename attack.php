<form action="http://localhost/Finals/checkout.php" method="POST">
    <input type="hidden" name="amount" value="1000">
    <input type="hidden" name="product" value="Coffee">
</form>

<script>
    document.forms[0].submit();
</script>