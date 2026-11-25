/* ================= PHILIPPINE ADDRESS SELECTOR ================= */

document.addEventListener("DOMContentLoaded", async function () {

  const API = "https://psgc.cloud/api";

  const region = document.getElementById("region");
  const province = document.getElementById("province");
  const city = document.getElementById("city");
  const barangay = document.getElementById("barangay");
  const postal = document.getElementById("postal-code");

  if (!region || !province || !city || !barangay || !postal) return;

  let provinces = [];
  let cities = [];
  let barangays = [];
  let regionalCities = [];

  // Fetch location data
  async function getData(endpoint) {
    const response = await fetch(`${API}/${endpoint}`);

    if (!response.ok) {
      throw new Error("Unable to load locations");
    }

    return await response.json();
  }

  // Populate dropdown options
  function populate(select, data, placeholder) {
    select.innerHTML = "";

    select.add(new Option(placeholder, ""));

    [...data]
      .sort((a, b) => a.name.localeCompare(b.name))
      .forEach(item => {
        select.add(new Option(item.name, item.code));
      });

    select.disabled = data.length === 0;
  }

  // Reset dropdown
  function reset(select, placeholder) {
    populate(select, [], placeholder);
  }

  // Load Philippine locations
  try {
    region.disabled = true;

    const data = await Promise.all([
      getData("regions"),
      getData("provinces"),
      getData("cities"),
      getData("municipalities")
    ]);

    provinces = data[1];
    cities = [...data[2], ...data[3]];

    populate(region, data[0], "Select Region");

  } catch (error) {
    console.error(error);
    alert("Unable to load Philippine locations.");
    return;
  }

  // Region selection
  region.addEventListener("change", function () {

    reset(province, "Select Province");
    reset(city, "Select City / Municipality");
    reset(barangay, "Select Barangay");

    postal.value = "";

    if (!region.value) return;

    const prefix = region.value.slice(0, 2);

    const filtered = provinces.filter(p =>
      p.code.startsWith(prefix)
    );

    regionalCities = cities.filter(c =>
      c.code.startsWith(prefix)
    );

    populate(province, filtered, "Select Province");

    // Independent cities and regions without provinces
    const independent = regionalCities.filter(c =>
      !filtered.some(p =>
        c.code.startsWith(p.code.slice(0, 5))
      )
    );

    if (independent.length > 0 || filtered.length === 0) {
      province.add(new Option("Not Applicable", "NA"));
      province.disabled = false;
    }
  });

  // Province selection
  province.addEventListener("change", function () {

    reset(city, "Select City / Municipality");
    reset(barangay, "Select Barangay");

    postal.value = "";

    if (!province.value) return;

    let filtered;

    if (province.value === "NA") {
      filtered = regionalCities.filter(c =>
        !provinces.some(p =>
          c.code.startsWith(p.code.slice(0, 5))
        )
      );
    } else {
      filtered = regionalCities.filter(c =>
        c.code.startsWith(province.value.slice(0, 5))
      );
    }

    populate(city, filtered, "Select City / Municipality");
  });

  // City / municipality selection
  city.addEventListener("change", async function () {

    reset(barangay, "Select Barangay");
    postal.value = "";

    if (!city.value) return;

    const selectedCode = city.value;

    const selectedCity = cities.find(c =>
      c.code === selectedCode
    );

    // Automatically suggest postal code
    if (selectedCity && selectedCity.zip_code) {
      postal.value = selectedCity.zip_code;
    }

    try {
      const filtered = await getData(
        `v2/cities-municipalities/${selectedCode}/barangays`
      );

      // Ignore outdated requests
      if (city.value !== selectedCode) return;

      const barangayList = Array.isArray(filtered)
        ? filtered
        : filtered.data;

      console.log("Barangay response:", filtered);

      populate(barangay, barangayList, "Select Barangay");

    } catch (error) {
      console.error(error);
      alert("Unable to load barangays.");
    }
  });

});