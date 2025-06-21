package main

import (
	"bytes"
	"encoding/base64"
	"encoding/hex"
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"strings"

	"github.com/grafov/m3u8"
	"github.com/unki2aut/go-mpd"
)

const MPD_URL = "https://drm.ipl2025.space/stream.mpd"

func main() {
	// keyid := getKeyId()
	// fmt.Println("Key ID:", keyid)
	http.HandleFunc("/", routeHandler)
	fmt.Println("Server started on http://localhost:8181")
	http.ListenAndServe(":8181", nil)
}

func routeHandler(w http.ResponseWriter, r *http.Request) {
	if strings.HasPrefix(r.URL.Path, "/media_playlist_") && strings.HasSuffix(r.URL.Path, ".m3u8") {
		mediaPlaylist(w, r)
		return
	}

	//Handle the key request
	if r.URL.Path == "/keys" {
		w.Header().Set("Content-Type", "application/json")
		keydata := getKeyId()

		// Defensive: check for nil and type assertion
		keyHex, ok1 := keydata["key_hex"].(string)
		kidHex, ok2 := keydata["kid_hex"].(string)
		if !ok1 || !ok2 || keyHex == "" || kidHex == "" {
			http.Error(w, "Key or KeyID missing from upstream", http.StatusInternalServerError)
			return
		}

		// Remove dashes from kidHex if present
		kidHex = strings.ReplaceAll(kidHex, "-", "")

		// Convert hex to bytes
		keyBytes, err1 := hex.DecodeString(keyHex)
		kidBytes, err2 := hex.DecodeString(kidHex)
		if err1 != nil || err2 != nil {
			http.Error(w, "Failed to decode hex key or kid", http.StatusInternalServerError)
			return
		}

		base64Key := base64.StdEncoding.EncodeToString(keyBytes)
		base64Kid := base64.StdEncoding.EncodeToString(kidBytes)

		resp := map[string]interface{}{
			"keys": []map[string]string{
				{
					"kty": "oct",
					"k":   base64Key,
					"kid": base64Kid,
				},
			},
		}

		jsonResp, err := json.Marshal(resp)
		if err != nil {
			http.Error(w, "Failed to marshal JSON", http.StatusInternalServerError)
			return
		}
		w.Write(jsonResp)
		return
	}

	if r.URL.Path == "/playlist.m3u8" {
		masterPlaylist(w, r)
		return
	}
	http.NotFound(w, r)
}

func getKeyId() map[string]interface{} {

	resp, _ := http.Get("https://drm.ipl2025.space/keys")

	body, _ := io.ReadAll(resp.Body)

	resp.Body.Close()

	var jsondata map[string]interface{}

	json.Unmarshal(body, &jsondata)

	return jsondata
}

func fetchMPD() (*mpd.MPD, error) {
	resp, err := http.Get(MPD_URL)
	if err != nil {
		return nil, fmt.Errorf("failed to fetch the MPD file: %v", err)
	}
	defer resp.Body.Close()

	body, err := io.ReadAll(resp.Body)
	if err != nil {
		return nil, fmt.Errorf("failed to read the MPD file: %v", err)
	}

	mpdObj := new(mpd.MPD)
	if err := mpdObj.Decode(body); err != nil {
		return nil, fmt.Errorf("failed to parse MPD: %v", err)
	}
	return mpdObj, nil
}

func masterPlaylist(w http.ResponseWriter, r *http.Request) {
	w.Header().Set("Content-Type", "application/vnd.apple.mpegurl")

	mpdObj, err := fetchMPD()
	if err != nil {
		http.Error(w, err.Error(), http.StatusBadGateway)
		return
	}

	master := m3u8.NewMasterPlaylist()

	for _, period := range mpdObj.Period {
		for _, adaptationSet := range period.AdaptationSets {
			isAudio := adaptationSet.MimeType == "audio/mp4"
			for _, representation := range adaptationSet.Representations {
				if representation.ID == nil {
					continue
				}

				codecs := ""
				if representation.Codecs != nil {
					codecs = *representation.Codecs
				} else if adaptationSet.Codecs != nil {
					codecs = *adaptationSet.Codecs
				}

				uri := fmt.Sprintf("/media_playlist_%s.m3u8", *representation.ID)
				resolution := ""
				if !isAudio && representation.Width != nil && representation.Height != nil {
					resolution = fmt.Sprintf("%dx%d", *representation.Width, *representation.Height)
				}

				bandwidth := uint32(0)
				if representation.Bandwidth != nil {
					bandwidth = uint32(*representation.Bandwidth)
				}

				master.Append(uri, nil, m3u8.VariantParams{
					Codecs:     codecs,
					Resolution: resolution,
					Bandwidth:  bandwidth,
				})

				fmt.Println("Master URI:", uri, "Bandwidth:", bandwidth, "Codecs:", codecs)
			}
		}
	}

	buf := &bytes.Buffer{}
	master.Encode().WriteTo(buf)
	w.Write(buf.Bytes())
}

func mediaPlaylist(w http.ResponseWriter, r *http.Request) {
	w.Header().Set("Content-Type", "application/vnd.apple.mpegurl")

	fmt.Println("Received request for media playlist:", r.URL.Path)
	// Extract representation ID from URL
	id := strings.TrimPrefix(r.URL.Path, "/media_playlist_")
	id = strings.TrimSuffix(id, ".m3u8")
	fmt.Println("Requested Representation ID:", id)

	if id == "" {
		http.Error(w, "Missing representation ID", http.StatusBadRequest)
		return
	}

	mpdObj, err := fetchMPD()
	if err != nil {
		http.Error(w, err.Error(), http.StatusBadGateway)
		return
	}

	found := false
	for _, period := range mpdObj.Period {
		baseUrl := ""
		if len(period.BaseURL) > 0 {
			baseUrl = strings.Split(period.BaseURL[0].Value, "?")[0]
		}

		for _, adaptationSet := range period.AdaptationSets {
			for _, representation := range adaptationSet.Representations {
				if representation.ID == nil || *representation.ID != id {
					continue
				}
				found = true

				// Use SegmentTemplate from Representation if available, else from AdaptationSet
				segmentTemplate := representation.SegmentTemplate
				if segmentTemplate == nil && adaptationSet.SegmentTemplate != nil {
					segmentTemplate = adaptationSet.SegmentTemplate
				}
				if segmentTemplate == nil {
					http.Error(w, "No segment template found", http.StatusInternalServerError)
					return
				}

				mediaPl, err := m3u8.NewMediaPlaylist(1000, 1000)
				if err != nil {
					http.Error(w, "Failed to create media playlist", http.StatusInternalServerError)
					return
				}

				init := strings.ReplaceAll(*segmentTemplate.Initialization, "$RepresentationID$", *representation.ID)
				mediaPl.Map = &m3u8.Map{URI: baseUrl + init}
				keydata := getKeyId()

				// Defensive: check for nil and type assertion
				keyHex, _ := keydata["key_hex"].(string)
				keyBytes, err1 := hex.DecodeString(keyHex)
				if err1 != nil {
					http.Error(w, "Failed to decode hex key or kid", http.StatusInternalServerError)
					return
				}

				base64Key := base64.StdEncoding.EncodeToString(keyBytes)

				mediaPl.Key = &m3u8.Key{
					Method: "AES-128",
					URI:    fmt.Sprintf("data:application/octet-stream;base64,%s==", base64Key),
				}
				timescale := *segmentTemplate.Timescale
				number := *segmentTemplate.StartNumber

				for _, s := range segmentTemplate.SegmentTimeline.S {
					segName := strings.ReplaceAll(*segmentTemplate.Media, "$Number$", fmt.Sprintf("%d", number))
					segName = strings.ReplaceAll(segName, "$RepresentationID$", *representation.ID)
					segURL := baseUrl + segName
					duration := float64(s.D) / float64(timescale)
					mediaPl.Append(segURL, duration, "")
					number++

					if s.R != nil && *s.R > 0 {
						for i := 0; i < int(*s.R); i++ {
							segName = strings.ReplaceAll(*segmentTemplate.Media, "$Number$", fmt.Sprintf("%d", number))
							segName = strings.ReplaceAll(segName, "$RepresentationID$", *representation.ID)
							mediaPl.Append(baseUrl+segName, duration, "")
							number++
						}
					}
				}

				if len(segmentTemplate.SegmentTimeline.S) > 0 {
					mediaPl.TargetDuration = float64(segmentTemplate.SegmentTimeline.S[0].D) / float64(timescale)
				}

				mediaPl.Close()
				buf := &bytes.Buffer{}
				mediaPl.Encode().WriteTo(buf)
				w.Write(buf.Bytes())
				return
			}
		}
	}

	if !found {
		http.Error(w, "Representation ID not found", http.StatusNotFound)
	}
}
