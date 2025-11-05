// Smooth scrolling for anchor links
$(document).ready(function(){
    $("a").on('click', function(event) {
        if (this.hash !== "") {
            event.preventDefault();
            var hash = this.hash;
            $('html, body').animate({
                scrollTop: $(hash).offset().top
            }, 800, function(){
                window.location.hash = hash;
            });
        }
    });
});

// Form Validation for Login and Registration forms
$(document).ready(function() {
    // Login Form Validation
    $("#loginForm").submit(function(event) {
        var email = $("#email").val();
        var password = $("#password").val();

        if (email === "" || password === "") {
            alert("Please fill in all fields.");
            event.preventDefault();
        }
    });

    // Registration Form Validation
    $("#registerForm").submit(function(event) {
        var email = $("#email").val();
        var password = $("#password").val();
        var confirmPassword = $("#confirmPassword").val();

        if (email === "" || password === "" || confirmPassword === "") {
            alert("Please fill in all fields.");
            event.preventDefault();
        } else if (password !== confirmPassword) {
            alert("Passwords do not match.");
            event.preventDefault();
        }
    });
});
