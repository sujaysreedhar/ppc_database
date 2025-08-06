import json
import requests
import time

API_KEY = "d1e87a1936f4476ca17c8a4e3d358660"

# Load checklist-data.json
with open("checklist-data.json", "r", encoding="utf-8") as f:
    checklist = json.load(f)

coords_map = {}

for item in checklist:
    pincode = str(item["pincode"])
    url = f"https://api.opencagedata.com/geocode/v1/json?q={pincode}&key={API_KEY}&countrycode=in"
    
    response = requests.get(url)
    if response.status_code == 200:
        data = response.json()
        if data["results"]:
            lat = data["results"][0]["geometry"]["lat"]
            lng = data["results"][0]["geometry"]["lng"]
            coords_map[pincode] = [lat, lng]
            print(f"{pincode}: {lat}, {lng}")
        else:
            print(f"No results for {pincode}")
    else:
        print(f"Error {response.status_code} for {pincode}")
    
    time.sleep(1)  # prevent hitting rate limits

# Save to JS file
with open("coordsMap.js", "w", encoding="utf-8") as f:
    f.write("const coordsMap = ")
    f.write(json.dumps(coords_map, indent=4))
    f.write(";\n")
    f.write("export default coordsMap;")

print("coordsMap.js created successfully.")
