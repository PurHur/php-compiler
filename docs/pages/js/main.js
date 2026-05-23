(function () {
  "use strict";

  var reveals = document.querySelectorAll(".reveal");
  if (!reveals.length) {
    return;
  }

  var observer = new IntersectionObserver(
    function (entries) {
      entries.forEach(function (entry) {
        if (entry.isIntersecting) {
          entry.target.classList.add("is-visible");
        }
      });
    },
    { rootMargin: "0px 0px -40px 0px", threshold: 0.08 }
  );

  reveals.forEach(function (el) {
    observer.observe(el);
  });

  var overallFill = document.getElementById("overall-progress-fill");
  if (overallFill) {
    var target = overallFill.getAttribute("data-width") || "42";
    requestAnimationFrame(function () {
      overallFill.style.width = target + "%";
    });
  }

  document.querySelectorAll(".mini-progress .progress-fill").forEach(function (el) {
    var w = el.getAttribute("data-width");
    if (w) {
      el.style.width = w + "%";
    }
  });
})();
