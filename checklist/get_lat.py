import csv
import json

# Step 1: Load valid pincode -> [lat, lon] mapping from CSV
pincode_lookup = {}

with open("5c2f62fe-5afa-4119-a499-fec9d604d5bd.csv", newline='', encoding='utf-8') as csvfile:
    reader = csv.DictReader(csvfile)
    for row in reader:
        pincode = row["pincode"].strip()
        lat_str = row["latitude"].strip()
        lon_str = row["longitude"].strip()

        try:
            if lat_str.lower() == "na" or lon_str.lower() == "na":
                continue
            lat = float(lat_str)
            lon = float(lon_str)
            if pincode not in pincode_lookup:
                pincode_lookup[pincode] = [lat, lon]
        except ValueError:
            continue

# Step 2: Load checklist data
with open("checklist-data.json", "r", encoding="utf-8") as f:
    checklist = json.load(f)

coords_map = {}
not_found = []

# Step 3: Match by pincode, or use default outside-India location
for item in checklist:
    pincode = str(item["pincode"])

    if pincode in pincode_lookup:
        coords_map[pincode] = pincode_lookup[pincode]
    else:
        default_lat = 35.0
        default_lon = -40.0
        coords_map[pincode] = [default_lat, default_lon]
        not_found.append({
            "pincode": pincode,
            "reason": "Not found in CSV. Using default out-of-India coordinates.",
            "lat": default_lat,
            "lon": default_lon
        })

# Step 4: Write coordsMap.js
with open("coordsMap.js", "w", encoding="utf-8") as f:
    f.write("const coordsMap = ")
    f.write(json.dumps(coords_map, indent=4))
    f.write(";\n")

# Step 5: Save debug info
with open("not_found.json", "w", encoding="utf-8") as nf:
    json.dump(not_found, nf, indent=4)

print("✅ coordsMap.js created. Default coordinates used for missing pincodes.")
